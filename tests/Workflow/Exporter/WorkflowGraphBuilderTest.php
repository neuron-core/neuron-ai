<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Exporter;

use InvalidArgumentException;
use NeuronAI\Tests\Workflow\Executor\Stub\DocumentParallelEvent;
use NeuronAI\Tests\Workflow\Executor\Stub\ImageProcessEvent;
use NeuronAI\Tests\Workflow\Executor\Stub\TextProcessEvent;
use NeuronAI\Tests\Workflow\Exporter\Stub\DescribedNode;
use NeuronAI\Tests\Workflow\Exporter\Stub\NullableReturnNode;
use NeuronAI\Tests\Workflow\Exporter\Stub\OtherDescribedNode;
use NeuronAI\Tests\Workflow\Exporter\Stub\UnsupportedTransition;
use NeuronAI\Tests\Workflow\Stub\ConditionalNode;
use NeuronAI\Tests\Workflow\Stub\FirstEvent;
use NeuronAI\Tests\Workflow\Stub\NodeOne;
use NeuronAI\Tests\Workflow\Stub\NodeThree;
use NeuronAI\Tests\Workflow\Stub\NodeTwo;
use NeuronAI\Tests\Workflow\Stub\SecondEvent;
use NeuronAI\Tests\Workflow\Stub\ThirdEvent;
use NeuronAI\Workflow\Events\StartEvent;
use NeuronAI\Workflow\Events\StopEvent;
use NeuronAI\Workflow\Exporter\EventTransition;
use NeuronAI\Workflow\Exporter\ExporterTransition;
use NeuronAI\Workflow\Exporter\ParallelTransition;
use NeuronAI\Workflow\Exporter\WorkflowGraph;
use NeuronAI\Workflow\Exporter\WorkflowGraphBuilder;
use NeuronAI\Workflow\Exporter\WorkflowGraphEdge;
use NeuronAI\Workflow\Exporter\WorkflowGraphVertex;
use NeuronAI\Workflow\Exporter\WorkflowGraphVertexType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;

use function array_map;
use function sha1;
use function sort;

class WorkflowGraphBuilderTest extends TestCase
{
    /**
     * Edges rendered with vertex labels, sorted, so assertions read as the topology.
     *
     * @return list<string>
     */
    protected function edges(WorkflowGraph $graph): array
    {
        $edges = array_map(
            static fn (WorkflowGraphEdge $edge): string => $graph->getVertex($edge->from)->label
                . ' -> ' . $graph->getVertex($edge->to)->label
                . ($edge->label === null ? '' : " [{$edge->label}]"),
            $graph->getEdges(),
        );
        sort($edges);

        return $edges;
    }

    public function test_a_linear_workflow_uses_class_hashes_as_ids_and_short_names_as_labels(): void
    {
        $graph = (new WorkflowGraphBuilder())->build(StartEvent::class, [
            StartEvent::class => new NodeOne(),
            FirstEvent::class => new NodeTwo(),
            SecondEvent::class => new NodeThree(),
        ]);

        $this->assertSame('event_' . sha1(StartEvent::class), $graph->startVertexId);
        $this->assertEquals([
            new WorkflowGraphVertex('event_' . sha1(StartEvent::class), 'StartEvent', WorkflowGraphVertexType::Event, StartEvent::class),
            new WorkflowGraphVertex('node_' . sha1(NodeOne::class), 'NodeOne', WorkflowGraphVertexType::Node, NodeOne::class),
            new WorkflowGraphVertex('event_' . sha1(FirstEvent::class), 'FirstEvent', WorkflowGraphVertexType::Event, FirstEvent::class),
            new WorkflowGraphVertex('node_' . sha1(NodeTwo::class), 'NodeTwo', WorkflowGraphVertexType::Node, NodeTwo::class),
            new WorkflowGraphVertex('event_' . sha1(SecondEvent::class), 'SecondEvent', WorkflowGraphVertexType::Event, SecondEvent::class),
            new WorkflowGraphVertex('node_' . sha1(NodeThree::class), 'NodeThree', WorkflowGraphVertexType::Node, NodeThree::class),
            new WorkflowGraphVertex('event_' . sha1(StopEvent::class), 'StopEvent', WorkflowGraphVertexType::Event, StopEvent::class),
        ], $graph->getVertices());
        $this->assertSame([
            'FirstEvent -> NodeTwo',
            'NodeOne -> FirstEvent',
            'NodeThree -> StopEvent',
            'NodeTwo -> SecondEvent',
            'NodeTwo -> StopEvent',
            'SecondEvent -> NodeThree',
            'StartEvent -> NodeOne',
        ], $this->edges($graph));
    }

    public function test_rebuilding_with_the_same_builder_is_deterministic_and_forgets_earlier_graphs(): void
    {
        $builder = new WorkflowGraphBuilder();
        $linear = [StartEvent::class => new NodeOne(), FirstEvent::class => new NodeTwo()];

        $first = $builder->build(StartEvent::class, $linear);
        $other = $builder->build(FirstEvent::class, [FirstEvent::class => new ConditionalNode()]);
        $again = $builder->build(StartEvent::class, $linear);

        $this->assertEquals($first, $again);
        $this->assertSame(['ConditionalNode -> SecondEvent', 'ConditionalNode -> ThirdEvent', 'FirstEvent -> ConditionalNode'], $this->edges($other));
    }

    public function test_union_and_nullable_return_types_become_transitions(): void
    {
        $union = (new WorkflowGraphBuilder())->build(FirstEvent::class, [FirstEvent::class => new ConditionalNode()]);
        $nullable = (new WorkflowGraphBuilder())->build(StartEvent::class, [StartEvent::class => new NullableReturnNode()]);

        $this->assertSame(['ConditionalNode -> SecondEvent', 'ConditionalNode -> ThirdEvent', 'FirstEvent -> ConditionalNode'], $this->edges($union));
        $this->assertSame(['NullableReturnNode -> FirstEvent', 'StartEvent -> NullableReturnNode'], $this->edges($nullable));
    }

    public function test_described_transitions_replace_return_type_inference(): void
    {
        $node = new DescribedNode([new EventTransition(FirstEvent::class), new EventTransition(ThirdEvent::class)]);

        $graph = (new WorkflowGraphBuilder())->build(StartEvent::class, [StartEvent::class => $node]);

        $this->assertSame(['DescribedNode -> FirstEvent', 'DescribedNode -> ThirdEvent', 'StartEvent -> DescribedNode'], $this->edges($graph));
        $this->assertSame(1, $node->describeCalls);
    }

    public function test_a_cycle_is_drawn_once_and_terminates(): void
    {
        $graph = (new WorkflowGraphBuilder())->build(StartEvent::class, [
            StartEvent::class => new DescribedNode([new EventTransition(FirstEvent::class)]),
            FirstEvent::class => new OtherDescribedNode([new EventTransition(StartEvent::class), new EventTransition(StopEvent::class)]),
        ]);

        $this->assertSame([
            'DescribedNode -> FirstEvent',
            'FirstEvent -> OtherDescribedNode',
            'OtherDescribedNode -> StartEvent',
            'OtherDescribedNode -> StopEvent',
            'StartEvent -> DescribedNode',
        ], $this->edges($graph));
    }

    public function test_events_unreachable_from_the_start_are_still_explored(): void
    {
        $graph = (new WorkflowGraphBuilder())->build(StartEvent::class, [
            StartEvent::class => new NodeOne(),
            SecondEvent::class => new NodeThree(),
        ]);

        $this->assertSame([
            'NodeOne -> FirstEvent',
            'NodeThree -> StopEvent',
            'SecondEvent -> NodeThree',
            'StartEvent -> NodeOne',
        ], $this->edges($graph));
    }

    public function test_parallel_branches_fork_and_join_and_stop_events_inside_branches_reach_the_join(): void
    {
        $graph = (new WorkflowGraphBuilder())->build(StartEvent::class, [
            StartEvent::class => new DescribedNode([new ParallelTransition(DocumentParallelEvent::class, [
                'text' => TextProcessEvent::class,
                'image' => ImageProcessEvent::class,
            ])]),
            TextProcessEvent::class => new NodeThree(),
            ImageProcessEvent::class => new OtherDescribedNode([new EventTransition(StopEvent::class)]),
            DocumentParallelEvent::class => new NodeTwo(),
        ]);

        $this->assertSame([
            'DescribedNode -> DocumentParallelEvent split',
            'DocumentParallelEvent -> NodeTwo',
            'DocumentParallelEvent join -> DocumentParallelEvent',
            'DocumentParallelEvent split -> ImageProcessEvent [image]',
            'DocumentParallelEvent split -> TextProcessEvent [text]',
            'ImageProcessEvent -> OtherDescribedNode',
            'NodeThree -> DocumentParallelEvent join',
            'NodeTwo -> SecondEvent',
            'NodeTwo -> StopEvent',
            'OtherDescribedNode -> DocumentParallelEvent join',
            'StartEvent -> DescribedNode',
            'TextProcessEvent -> NodeThree',
        ], $this->edges($graph));
        $types = array_map(static fn (WorkflowGraphVertex $vertex): WorkflowGraphVertexType => $vertex->type, $graph->getVertices());
        $this->assertContains(WorkflowGraphVertexType::ParallelSplit, $types);
        $this->assertContains(WorkflowGraphVertexType::ParallelJoin, $types);
    }

    public function test_an_event_reached_inside_and_outside_a_branch_is_described_once_and_drawn_in_both_contexts(): void
    {
        $shared = new OtherDescribedNode([new EventTransition(StopEvent::class)]);

        $graph = (new WorkflowGraphBuilder())->build(StartEvent::class, [
            StartEvent::class => new DescribedNode([
                new EventTransition(TextProcessEvent::class),
                new ParallelTransition(DocumentParallelEvent::class, ['text' => TextProcessEvent::class]),
            ]),
            TextProcessEvent::class => $shared,
        ]);

        $this->assertSame(1, $shared->describeCalls);
        $this->assertContains('OtherDescribedNode -> StopEvent', $this->edges($graph));
        $this->assertContains('OtherDescribedNode -> DocumentParallelEvent join', $this->edges($graph));
    }

    public function test_branches_to_the_same_event_keep_one_labelled_edge_each(): void
    {
        $graph = (new WorkflowGraphBuilder())->build(StartEvent::class, [
            StartEvent::class => new DescribedNode([new ParallelTransition(DocumentParallelEvent::class, [
                'first' => TextProcessEvent::class,
                'second' => TextProcessEvent::class,
            ])]),
            TextProcessEvent::class => new NodeThree(),
        ]);

        $this->assertSame([
            'DescribedNode -> DocumentParallelEvent split',
            'DocumentParallelEvent join -> DocumentParallelEvent',
            'DocumentParallelEvent split -> TextProcessEvent [first]',
            'DocumentParallelEvent split -> TextProcessEvent [second]',
            'NodeThree -> DocumentParallelEvent join',
            'StartEvent -> DescribedNode',
            'TextProcessEvent -> NodeThree',
        ], $this->edges($graph));
    }

    public function test_alternative_fan_outs_of_one_parallel_event_get_their_own_split_and_join(): void
    {
        $graph = (new WorkflowGraphBuilder())->build(StartEvent::class, [
            StartEvent::class => new DescribedNode([
                new ParallelTransition(DocumentParallelEvent::class, ['text' => TextProcessEvent::class]),
                new ParallelTransition(DocumentParallelEvent::class, ['image' => ImageProcessEvent::class]),
            ]),
            TextProcessEvent::class => new NodeThree(),
            ImageProcessEvent::class => new OtherDescribedNode([new EventTransition(StopEvent::class)]),
        ]);

        $this->assertSame([
            'DescribedNode -> DocumentParallelEvent split',
            'DescribedNode -> DocumentParallelEvent split',
            'DocumentParallelEvent join -> DocumentParallelEvent',
            'DocumentParallelEvent join -> DocumentParallelEvent',
            'DocumentParallelEvent split -> ImageProcessEvent [image]',
            'DocumentParallelEvent split -> TextProcessEvent [text]',
            'ImageProcessEvent -> OtherDescribedNode',
            'NodeThree -> DocumentParallelEvent join',
            'OtherDescribedNode -> DocumentParallelEvent join',
            'StartEvent -> DescribedNode',
            'TextProcessEvent -> NodeThree',
        ], $this->edges($graph));
    }

    /** @return array<string, array{string, string, string}> */
    public static function invalidTransitionProvider(): array
    {
        return [
            'event transition to a non-event' => [
                'event',
                stdClass::class,
                'stdClass must implement NeuronAI\Workflow\Events\Event',
            ],
            'parallel transition without a parallel event' => [
                'parallel',
                FirstEvent::class,
                FirstEvent::class . ' must extend NeuronAI\Workflow\Events\ParallelEvent',
            ],
            'parallel branch to a non-event' => [
                'branch',
                stdClass::class,
                'stdClass must implement NeuronAI\Workflow\Events\Event',
            ],
        ];
    }

    #[DataProvider('invalidTransitionProvider')]
    public function test_a_described_transition_to_a_wrong_class_is_rejected(string $kind, string $class, string $message): void
    {
        $transition = match ($kind) {
            'event' => new EventTransition($class),
            'parallel' => new ParallelTransition($class, ['text' => TextProcessEvent::class]),
            default => new ParallelTransition(DocumentParallelEvent::class, ['text' => $class]),
        };

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        (new WorkflowGraphBuilder())->build(StartEvent::class, [StartEvent::class => new DescribedNode([$transition])]);
    }

    public function test_an_unsupported_transition_kind_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Exporter transitions must implement ' . ExporterTransition::class);

        (new WorkflowGraphBuilder())->build(StartEvent::class, [StartEvent::class => new DescribedNode([new UnsupportedTransition()])]);
    }
}
