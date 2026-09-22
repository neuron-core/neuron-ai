<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Executor;

use NeuronAI\Tests\Workflow\Executor\Stub\DocumentParallelProcessing;
use NeuronAI\Tests\Workflow\Executor\Stub\ImageProcessNode;
use NeuronAI\Tests\Workflow\Executor\Stub\MergeNode;
use NeuronAI\Tests\Workflow\Executor\Stub\RestorableStateNode;
use NeuronAI\Tests\Workflow\Executor\Stub\RestorableStateFork;
use NeuronAI\Tests\Workflow\Executor\Stub\RestoringStateWorkflow;
use NeuronAI\Tests\Workflow\Executor\Stub\StatefulTextProcessNode;
use NeuronAI\Tests\Workflow\Executor\Stub\TextProcessEvent;
use NeuronAI\Workflow\Executor\AsyncExecutor;
use NeuronAI\Workflow\Persistence\InMemoryPersistence;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class StateRestorationTest extends TestCase
{
    #[TestWith([false])]
    #[TestWith([true])]
    public function test_cached_branch_state_restores_dependencies_before_execution(bool $async): void
    {
        $persistence = new InMemoryPersistence();
        $make = function (bool $fail) use ($persistence, $async): RestoringStateWorkflow {
            $workflow = RestoringStateWorkflow::make('restorable-branch');
            $workflow->setPersistence($persistence)->addNodes([
                new DocumentParallelProcessing(),
                new StatefulTextProcessNode(),
                new RestorableStateNode(fail: $fail),
                new ImageProcessNode(),
                new MergeNode(),
            ]);
            if ($async) {
                $workflow->setExecutor(new AsyncExecutor());
            }

            return $workflow;
        };

        try {
            $make(true)->run();
            $this->fail('Expected failure after the branch state was recorded.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Probe failed.', $exception->getMessage());
        }

        $resumed = $make(false);
        $state = $resumed->run(\NeuronAI\Workflow\Executor\ExecutionRequest::resume());
        $this->assertSame('restored:42', $state->get('analysis')['text']);
        $this->assertContains('text', $resumed->restorations);
        $this->assertFalse($state->has('branch_value'));
    }

    public function test_retained_outcome_returns_data_without_constructing_execution_dependencies(): void
    {
        $persistence = new InMemoryPersistence();
        $first = RestoringStateWorkflow::make('restorable-outcome');
        $first->setPersistence($persistence)->retainCompletionUntilAcknowledged();
        $first->setStartEvent(new TextProcessEvent())->addNodes([
            new StatefulTextProcessNode(),
            new RestorableStateNode(),
        ]);
        $state = $first->run();
        $this->assertSame('live', ($state->operation)());
        $this->assertSame([], $first->restorations, 'Live state must not pass through restoration.');

        $resumed = RestoringStateWorkflow::make('restorable-outcome');
        $resumed->setPersistence($persistence)->retainCompletionUntilAcknowledged();
        $restored = $resumed->run(\NeuronAI\Workflow\Executor\ExecutionRequest::resume());
        $this->assertNull($restored->operation);
        $this->assertSame([], $resumed->restorations);
    }

    public function test_resumed_branches_restore_dependencies_but_unanswered_checkpoint_is_data_only(): void
    {
        $persistence = new InMemoryPersistence();
        $make = function () use ($persistence): RestoringStateWorkflow {
            $workflow = RestoringStateWorkflow::make('restorable-interrupts');
            $workflow->setPersistence($persistence)->addNodes([
                new RestorableStateFork(),
                new StatefulTextProcessNode(),
                new RestorableStateNode(suspend: true),
                new MergeNode(),
            ]);

            return $workflow;
        };

        $first = $make();
        $textRequest = $first->run()->getInterruptRequest();
        $this->assertNotNull($textRequest);

        $partial = $make();
        $state = $partial->run(\NeuronAI\Workflow\Executor\ExecutionRequest::signal('text', []));
        $this->assertTrue($state->isInterrupted());
        $this->assertContains('text', $partial->restorations);

        $stale = $make();
        $checkpoint = $stale->run(\NeuronAI\Workflow\Executor\ExecutionRequest::resume());
        $this->assertTrue($checkpoint->isInterrupted());
        $this->assertNull($checkpoint->operation);
        $this->assertSame([], $stale->restorations);

        $last = $make();
        $completed = $last->run(\NeuronAI\Workflow\Executor\ExecutionRequest::signal('image', []));
        $this->assertFalse($completed->isInterrupted());
        $this->assertSame(['text' => 'restored:42', 'image' => 'restored:42'], $completed->get('analysis'));
    }
}
