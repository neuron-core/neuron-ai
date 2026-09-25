<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Executor;

use NeuronAI\Tests\Workflow\Executor\Stub\DocumentParallelProcessing;
use NeuronAI\Tests\Workflow\Executor\Stub\ImageProcessNode;
use NeuronAI\Tests\Workflow\Executor\Stub\MergeNode;
use NeuronAI\Tests\Workflow\Executor\Stub\ResourcefulNode;
use NeuronAI\Tests\Workflow\Executor\Stub\ResourcefulWorkflow;
use NeuronAI\Tests\Workflow\Executor\Stub\StatefulTextProcessNode;
use NeuronAI\Tests\Workflow\Executor\Stub\TextBranchesFork;
use NeuronAI\Tests\Workflow\Executor\Stub\TextProcessEvent;
use NeuronAI\Workflow\Executor\AsyncBranchRunner;
use NeuronAI\Workflow\Executor\ExecutionRequest;
use NeuronAI\Workflow\Persistence\InMemoryPersistence;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Resources are built once for every execution segment and never persisted:
 * a resumed segment builds them again, while saved outcomes and idle polls,
 * which run no node, build none.
 */
class SegmentResourcesTest extends TestCase
{
    #[TestWith([false])]
    #[TestWith([true])]
    public function test_a_resumed_branch_uses_the_resources_of_its_segment(bool $async): void
    {
        $persistence = new InMemoryPersistence();
        $make = function (bool $fail) use ($persistence, $async): ResourcefulWorkflow {
            $workflow = ResourcefulWorkflow::make('resourceful-branch');
            $workflow->setPersistence($persistence)->addNodes([
                new DocumentParallelProcessing(),
                new StatefulTextProcessNode(),
                new ResourcefulNode(fail: $fail),
                new ImageProcessNode(),
                new MergeNode(),
            ]);
            if ($async) {
                $workflow->setBranchRunner(new AsyncBranchRunner());
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
        $state = $resumed->run(ExecutionRequest::resume());
        $this->assertSame('segment:42', $state->get('analysis')['text']);
        $this->assertSame(1, $resumed->resourceBuilds);
        $this->assertFalse($state->has('branch_value'));
    }

    public function test_a_retained_outcome_builds_no_resources(): void
    {
        $persistence = new InMemoryPersistence();
        $first = ResourcefulWorkflow::make('resourceful-outcome');
        $first->setPersistence($persistence)->retainCompletionUntilAcknowledged();
        $first->setStartEvent(new TextProcessEvent())->addNodes([
            new StatefulTextProcessNode(),
            new ResourcefulNode(),
        ]);
        $first->run();
        $this->assertSame(1, $first->resourceBuilds);

        $resumed = ResourcefulWorkflow::make('resourceful-outcome');
        $resumed->setPersistence($persistence)->retainCompletionUntilAcknowledged();
        $this->assertSame(42, $resumed->run(ExecutionRequest::resume())->get('branch_value'));
        $this->assertSame(0, $resumed->resourceBuilds);
    }

    public function test_resumed_branches_build_resources_but_an_idle_poll_builds_none(): void
    {
        $persistence = new InMemoryPersistence();
        $make = function () use ($persistence): ResourcefulWorkflow {
            $workflow = ResourcefulWorkflow::make('resourceful-interrupts');
            $workflow->setPersistence($persistence)->addNodes([
                new TextBranchesFork(),
                new StatefulTextProcessNode(),
                new ResourcefulNode(suspend: true),
                new MergeNode(),
            ]);

            return $workflow;
        };

        $this->assertNotNull($make()->run()->getInterruptRequest());

        $partial = $make();
        $this->assertTrue($partial->run(ExecutionRequest::signal('text', []))->isInterrupted());
        $this->assertSame(1, $partial->resourceBuilds);

        $stale = $make();
        $this->assertTrue($stale->run(ExecutionRequest::resume())->isInterrupted());
        $this->assertSame(0, $stale->resourceBuilds);

        $last = $make();
        $completed = $last->run(ExecutionRequest::signal('image', []));
        $this->assertFalse($completed->isInterrupted());
        $this->assertSame(['text' => 'segment:42', 'image' => 'segment:42'], $completed->get('analysis'));
    }
}
