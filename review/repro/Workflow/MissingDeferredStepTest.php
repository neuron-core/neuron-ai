<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Executor;

use NeuronAI\Exceptions\PersistenceException;
use NeuronAI\Tests\Workflow\Executor\Stub\ConcurrentWaitNode;
use NeuronAI\Tests\Workflow\Executor\Stub\DocumentParallelEvent;
use NeuronAI\Tests\Workflow\Executor\Stub\TextProcessEvent;
use NeuronAI\Workflow\Events\StartEvent;
use NeuronAI\Workflow\Events\StopEvent;
use NeuronAI\Workflow\Executor\AsyncBranchRunner;
use NeuronAI\Workflow\Executor\ExecutionRequest;
use NeuronAI\Workflow\Executor\WorkflowControl;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\Persistence\InMemoryPersistence;
use NeuronAI\Workflow\Persistence\PhpSerializer;
use NeuronAI\Workflow\Workflow;
use NeuronAI\Workflow\WorkflowState;
use PHPUnit\Framework\TestCase;
use stdClass;

class MissingDeferredStepTest extends TestCase
{
    public function test_a_missing_deferred_step_record_fails_as_a_persistence_error(): void
    {
        $persistence = new class () extends InMemoryPersistence {
            public ?string $hidden = null;

            public function get(string $partition, string $key): ?string
            {
                return $key === $this->hidden ? null : parent::get($partition, $key);
            }
        };
        $trace = new stdClass();
        $trace->events = [];
        $make = function () use ($persistence, $trace): Workflow {
            $fork = new class () extends Node {
                public function __invoke(StartEvent $event, WorkflowState $state): DocumentParallelEvent
                {
                    return new DocumentParallelEvent(['b' => new TextProcessEvent(), 'a' => new TextProcessEvent()]);
                }
            };
            $join = new class () extends Node {
                public function __invoke(DocumentParallelEvent $event, WorkflowState $state): StopEvent
                {
                    return new StopEvent();
                }
            };

            return Workflow::make('missing-deferred')->setPersistence($persistence)
                ->setBranchRunner(new AsyncBranchRunner())->addNodes([$fork, new ConcurrentWaitNode($trace), $join]);
        };
        $make()->run();
        $control = (new PhpSerializer())->unserialize((string) $persistence->get('missing-deferred', '__control'));
        $this->assertInstanceOf(WorkflowControl::class, $control);
        $this->assertCount(1, $control->pendingSteps);
        $pendingKey = $control->runId . '/' . $control->pendingSteps[0];
        $persistence->hidden = $pendingKey;

        try {
            $make()->run(ExecutionRequest::resume(['value' => 'A']));
            $this->fail('Resuming over a missing deferred step record must fail.');
        } catch (PersistenceException $e) {
            $this->assertStringContainsString($pendingKey, $e->getMessage());
        }
    }
}
