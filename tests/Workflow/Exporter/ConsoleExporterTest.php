<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Exporter;

use NeuronAI\Tests\Workflow\Executor\Stub\DocumentParallelProcessing;
use NeuronAI\Tests\Workflow\Executor\Stub\IgnitionStartEvent;
use NeuronAI\Tests\Workflow\Executor\Stub\IgnitionWaitNode;
use NeuronAI\Tests\Workflow\Executor\Stub\ImageProcessNode;
use NeuronAI\Tests\Workflow\Executor\Stub\MergeNode;
use NeuronAI\Tests\Workflow\Executor\Stub\TextProcessNode;
use NeuronAI\Tests\Workflow\Exporter\Stub\FinalStopEvent;
use NeuronAI\Tests\Workflow\Stub\NodeOne;
use NeuronAI\Tests\Workflow\Stub\NodeThree;
use NeuronAI\Tests\Workflow\Stub\NodeTwo;
use NeuronAI\Workflow\Events\StopEvent;
use NeuronAI\Workflow\Exporter\ConsoleExporter;
use NeuronAI\Workflow\Exporter\WorkflowGraph;
use NeuronAI\Workflow\Exporter\WorkflowGraphEdge;
use NeuronAI\Workflow\Exporter\WorkflowGraphVertex;
use NeuronAI\Workflow\Exporter\WorkflowGraphVertexType;
use NeuronAI\Workflow\Workflow;
use PHPUnit\Framework\TestCase;

use function str_repeat;

class ConsoleExporterTest extends TestCase
{
    protected const HEADER = "Workflow Structure:\n==================================================\n\n";

    /**
     * @param array<string, WorkflowGraphVertexType> $vertices
     * @param list<array{0: string, 1: string, 2?: string}> $edges
     * @param array<string, class-string> $classes
     */
    protected function graph(string $start, array $vertices, array $edges, array $classes = []): WorkflowGraph
    {
        $graph = new WorkflowGraph($start);
        foreach ($vertices as $id => $type) {
            $graph->addVertex(new WorkflowGraphVertex($id, $id, $type, $classes[$id] ?? null));
        }
        foreach ($edges as $edge) {
            $graph->addEdge(new WorkflowGraphEdge($edge[0], $edge[1], $edge[2] ?? null));
        }

        return $graph;
    }

    public function test_a_workflow_renders_as_an_indented_tree_from_its_start_event(): void
    {
        $output = Workflow::make()->addNodes([new NodeOne(), new NodeTwo(), new NodeThree()])->export();

        $this->assertSame(self::HEADER . <<<'TREE'
            🏁 StartEvent
               ↓
              ⚡ NodeOne
                 ↓
                🔗 FirstEvent
                   ↓
                  ⚡ NodeTwo
                     ↓
                    🔗 SecondEvent
                       ↓
                      ⚡ NodeThree
                         ↓
                        🏁 StopEvent
                     ↓
                    ↳ StopEvent [Already shown]

            TREE, $output);
    }

    public function test_parallel_branches_are_labelled_and_connected_to_the_join(): void
    {
        $output = Workflow::make()
            ->addNodes([
                new DocumentParallelProcessing(),
                new TextProcessNode(),
                new ImageProcessNode(),
                new MergeNode(),
            ])
            ->export();

        $this->assertStringContainsString("  ⚡ DocumentParallelProcessing\n     ↓\n    ⑂ DocumentParallelEvent split\n", $output);
        $this->assertStringContainsString("       [text] ↓\n      🔗 TextProcessEvent\n", $output);
        $this->assertStringContainsString("       [image] ↓\n      🔗 ImageProcessEvent\n", $output);
        $this->assertStringContainsString("⑃ DocumentParallelEvent join\n", $output);
        $this->assertStringContainsString("⚡ MergeNode\n", $output);
        $this->assertStringNotContainsString('Orphaned Node', $output);
        $this->assertStringNotContainsString('Cycle detected', $output);
    }

    public function test_custom_start_event_is_the_graph_root(): void
    {
        $output = Workflow::make()
            ->setStartEvent(new IgnitionStartEvent())
            ->addNode(new IgnitionWaitNode())
            ->export();

        $this->assertStringStartsWith(self::HEADER . "🏁 IgnitionStartEvent\n   ↓\n  ⚡ IgnitionWaitNode\n", $output);
    }

    public function test_a_cycle_is_marked_instead_of_followed(): void
    {
        $graph = $this->graph('Loop', [
            'Loop' => WorkflowGraphVertexType::Event,
            'Retry' => WorkflowGraphVertexType::Node,
        ], [['Loop', 'Retry'], ['Retry', 'Loop', 'again']]);

        $this->assertSame(self::HEADER . <<<'TREE'
            🏁 Loop
               ↓
              ⚡ Retry
                 [again] ↓
                ↻ Loop [Cycle detected]

            TREE, (new ConsoleExporter())->export($graph));
    }

    public function test_unreachable_events_are_rendered_as_orphans_once(): void
    {
        $graph = $this->graph('Start', [
            'Start' => WorkflowGraphVertexType::Event,
            'Handler' => WorkflowGraphVertexType::Node,
            'Orphan' => WorkflowGraphVertexType::Event,
            'OrphanHandler' => WorkflowGraphVertexType::Node,
            'Finished' => WorkflowGraphVertexType::Event,
        ], [['Start', 'Handler'], ['Orphan', 'OrphanHandler'], ['OrphanHandler', 'Finished']], ['Finished' => FinalStopEvent::class]);

        $this->assertSame(self::HEADER . <<<'TREE'
            🏁 Start
               ↓
              ⚡ Handler

            TREE . "\n" . str_repeat('─', 30) . "\nOrphaned Node:\n" . <<<'TREE'
            🔗 Orphan
               ↓
              ⚡ OrphanHandler
                 ↓
                🏁 Finished

            TREE, (new ConsoleExporter())->export($graph));
    }

    public function test_split_join_and_stop_vertices_have_distinct_icons(): void
    {
        $graph = $this->graph('Start', [
            'Start' => WorkflowGraphVertexType::Event,
            'Split' => WorkflowGraphVertexType::ParallelSplit,
            'Join' => WorkflowGraphVertexType::ParallelJoin,
            'Stop' => WorkflowGraphVertexType::Event,
        ], [['Start', 'Split'], ['Split', 'Join', 'branch'], ['Join', 'Stop']], ['Stop' => StopEvent::class]);

        $this->assertSame(self::HEADER . <<<'TREE'
            🏁 Start
               ↓
              ⑂ Split
                 [branch] ↓
                ⑃ Join
                   ↓
                  🏁 Stop

            TREE, (new ConsoleExporter())->export($graph));
    }
}
