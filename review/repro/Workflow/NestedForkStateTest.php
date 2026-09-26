<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Executor;

use NeuronAI\Tests\Workflow\Executor\Stub\DocumentParallelEvent;
use NeuronAI\Tests\Workflow\Executor\Stub\ImageProcessEvent;
use NeuronAI\Tests\Workflow\Executor\Stub\MergeNode;
use NeuronAI\Tests\Workflow\Executor\Stub\SummaryProcessEvent;
use NeuronAI\Tests\Workflow\Executor\Stub\TextProcessEvent;
use NeuronAI\Tests\Workflow\Executor\Stub\ThreeBranchParallelEvent;
use NeuronAI\Workflow\Events\StartEvent;
use NeuronAI\Workflow\Events\StopEvent;
use NeuronAI\Workflow\Executor\AsyncBranchRunner;
use NeuronAI\Workflow\Executor\BranchRunner;
use NeuronAI\Workflow\Executor\SequentialBranchRunner;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\Workflow;
use NeuronAI\Workflow\WorkflowState;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class NestedForkStateTest extends TestCase
{
    /** @return array<string, array{BranchRunner}> */
    public static function runners(): array
    {
        return ['sequential' => [new SequentialBranchRunner()], 'async' => [new AsyncBranchRunner()]];
    }

    #[DataProvider('runners')]
    public function test_a_nested_branch_starts_from_its_parent_branch_state(BranchRunner $runner): void
    {
        $fork = new class () extends Node {
            public function __invoke(StartEvent $event, WorkflowState $state): DocumentParallelEvent
            {
                $state->set('prepared_by_fork', 'root');

                return new DocumentParallelEvent(['text' => new TextProcessEvent(), 'image' => new ImageProcessEvent()]);
            }
        };
        $text = new class () extends Node {
            public function __invoke(TextProcessEvent $event, WorkflowState $state): ThreeBranchParallelEvent
            {
                $state->set('prepared_by_parent_branch', 'text');

                return new ThreeBranchParallelEvent(['leaf' => new SummaryProcessEvent()]);
            }
        };
        $leaf = new class () extends Node {
            public function __invoke(SummaryProcessEvent $event, WorkflowState $state): StopEvent
            {
                return new StopEvent([$state->get('prepared_by_fork'), $state->get('prepared_by_parent_branch')]);
            }
        };
        $innerJoin = new class () extends Node {
            public function __invoke(ThreeBranchParallelEvent $event, WorkflowState $state): StopEvent
            {
                return new StopEvent(['leaf' => $event->getResult('leaf'), 'join' => $state->get('prepared_by_parent_branch')]);
            }
        };
        $image = new class () extends Node {
            public function __invoke(ImageProcessEvent $event, WorkflowState $state): StopEvent
            {
                return new StopEvent('image');
            }
        };

        $state = Workflow::make('nested-state')
            ->setBranchRunner($runner)
            ->addNodes([$fork, $text, $leaf, $innerJoin, $image, new MergeNode()])
            ->run();

        $this->assertSame(['leaf' => ['root', 'text'], 'join' => 'text'], $state->get('analysis')['text']);
    }
}
