<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Exporter;

use Generator;
use NeuronAI\Tests\Workflow\Executor\Stub\DocumentParallelProcessing;
use NeuronAI\Tests\Workflow\Executor\Stub\ImageProcessNode;
use NeuronAI\Tests\Workflow\Executor\Stub\MergeNode;
use NeuronAI\Tests\Workflow\Executor\Stub\TextProcessNode;
use NeuronAI\Tests\Workflow\Stub\ConditionalNode;
use NeuronAI\Tests\Workflow\Stub\NodeForSecond;
use NeuronAI\Tests\Workflow\Stub\NodeForThird;
use NeuronAI\Tests\Workflow\Stub\NodeOne;
use NeuronAI\Tests\Workflow\Stub\NodeThree;
use NeuronAI\Tests\Workflow\Stub\NodeTwo;
use NeuronAI\Workflow\Events\StartEvent;
use NeuronAI\Workflow\Events\StopEvent;
use NeuronAI\Workflow\Exporter\MermaidExporter;
use NeuronAI\Workflow\Exporter\WorkflowGraph;
use NeuronAI\Workflow\Exporter\WorkflowGraphVertex;
use NeuronAI\Workflow\Exporter\WorkflowGraphVertexType;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\Workflow;
use NeuronAI\Workflow\WorkflowState;
use PHPUnit\Framework\TestCase;

use function array_filter;
use function array_unique;
use function count;
use function explode;
use function preg_match;
use function preg_quote;
use function substr_count;
use function trim;

class MermaidExporterTest extends TestCase
{
    public function test_basic_mermaid_export(): void
    {
        $output = Workflow::make()
            ->addNodes([
                new NodeOne(),
                new NodeTwo(),
                new NodeThree(),
            ])
            ->setExporter(new MermaidExporter())
            ->export();

        $this->assertStringStartsWith('graph TD', $output);
        $this->assertEdge($output, 'StartEvent', 'NodeOne');
        $this->assertEdge($output, 'NodeOne', 'FirstEvent');
        $this->assertEdge($output, 'FirstEvent', 'NodeTwo');
        $this->assertEdge($output, 'NodeTwo', 'SecondEvent');
        $this->assertEdge($output, 'SecondEvent', 'NodeThree');
        $this->assertEdge($output, 'NodeThree', 'StopEvent');
    }

    public function test_union_return_types_are_exported(): void
    {
        $output = Workflow::make()
            ->addNodes([
                new NodeOne(),
                new ConditionalNode(),
                new NodeForSecond(),
                new NodeForThird(),
            ])
            ->setExporter(new MermaidExporter())
            ->export();

        $this->assertEdge($output, 'ConditionalNode', 'SecondEvent');
        $this->assertEdge($output, 'ConditionalNode', 'ThirdEvent');
        $this->assertEdge($output, 'SecondEvent', 'NodeForSecond');
        $this->assertEdge($output, 'ThirdEvent', 'NodeForThird');
    }

    public function test_parallel_branches_connect_to_the_join(): void
    {
        $output = Workflow::make()
            ->addNodes([
                new DocumentParallelProcessing(),
                new TextProcessNode(),
                new ImageProcessNode(),
                new MergeNode(),
            ])
            ->setExporter(new MermaidExporter())
            ->export();

        $this->assertEdge($output, 'DocumentParallelProcessing', 'DocumentParallelEvent split');
        $this->assertEdge($output, 'DocumentParallelEvent split', 'TextProcessEvent', 'text');
        $this->assertEdge($output, 'DocumentParallelEvent split', 'ImageProcessEvent', 'image');
        $this->assertEdge($output, 'TextProcessEvent', 'TextProcessNode');
        $this->assertEdge($output, 'ImageProcessEvent', 'ImageProcessNode');
        $this->assertEdge($output, 'TextProcessNode', 'DocumentParallelEvent join');
        $this->assertEdge($output, 'ImageProcessNode', 'DocumentParallelEvent join');
        $this->assertEdge($output, 'DocumentParallelEvent join', 'DocumentParallelEvent');
        $this->assertEdge($output, 'DocumentParallelEvent', 'MergeNode');
        $this->assertEdge($output, 'MergeNode', 'StopEvent');
    }

    public function test_generator_is_not_exported_as_an_event(): void
    {
        $node = new class () extends Node {
            public function __invoke(StartEvent $event, WorkflowState $state): Generator
            {
                yield 'chunk';
                return new StopEvent();
            }
        };

        $output = Workflow::make()
            ->addNode($node)
            ->setExporter(new MermaidExporter())
            ->export();

        $this->assertStringNotContainsString('["Generator"]', $output);
    }

    public function test_mermaid_export_uses_short_labels_and_stable_ids(): void
    {
        $output = Workflow::make()
            ->addNodes([new NodeOne(), new NodeTwo()])
            ->setExporter(new MermaidExporter())
            ->export();

        $this->assertMatchesRegularExpression('/event_[a-f0-9]{40}\["StartEvent"\]/', $output);
        $this->assertMatchesRegularExpression('/node_[a-f0-9]{40}\[\["NodeOne"\]\]/', $output);
        $this->assertStringNotContainsString('Tests\\Workflow\\Stub\\', $output);
        $this->assertStringNotContainsString('NeuronAI\\Workflow\\', $output);
    }

    public function test_vertices_with_the_same_short_label_remain_distinct(): void
    {
        $graph = new WorkflowGraph('event_one');
        $graph->addVertex(new WorkflowGraphVertex(
            'event_one',
            'DuplicateEvent',
            WorkflowGraphVertexType::Event,
            'First\\DuplicateEvent',
        ));
        $graph->addVertex(new WorkflowGraphVertex(
            'event_two',
            'DuplicateEvent',
            WorkflowGraphVertexType::Event,
            'Second\\DuplicateEvent',
        ));

        $output = new MermaidExporter()->export($graph);

        $this->assertStringContainsString('event_one["DuplicateEvent"]', $output);
        $this->assertStringContainsString('event_two["DuplicateEvent"]', $output);
        $this->assertSame(2, substr_count($output, '["DuplicateEvent"]'));
    }

    public function test_mermaid_export_has_no_duplicate_lines(): void
    {
        $output = Workflow::make()
            ->addNodes([new NodeOne(), new NodeTwo(), new NodeThree()])
            ->setExporter(new MermaidExporter())
            ->export();
        $lines = array_filter(
            explode("\n", $output),
            fn (string $line): bool => trim($line) !== '' && trim($line) !== 'graph TD',
        );

        $this->assertCount(count($lines), array_unique($lines));
    }

    public function test_mermaid_export_uses_valid_statement_syntax(): void
    {
        $output = Workflow::make()
            ->addNodes([
                new DocumentParallelProcessing(),
                new TextProcessNode(),
                new ImageProcessNode(),
                new MergeNode(),
            ])
            ->setExporter(new MermaidExporter())
            ->export();
        $lines = explode("\n", $output);

        $this->assertSame('graph TD', trim($lines[0]));

        foreach (array_filter($lines, fn (string $line): bool => trim($line) !== '' && trim($line) !== 'graph TD') as $line) {
            $vertex = preg_match('/^\s+\w+(?:\["[^"]*"\]|\[\["[^"]*"\]\]|\{"[^"]*"\})$/', $line) === 1;
            $edge = preg_match('/^\s+\w+ -->(?:\|"[^"]*"\|)? \w+$/', $line) === 1;
            $this->assertTrue($vertex || $edge, "Invalid Mermaid statement: {$line}");
        }
    }

    protected function assertEdge(
        string $output,
        string $fromLabel,
        string $toLabel,
        ?string $edgeLabel = null,
    ): void {
        $from = $this->vertexId($output, $fromLabel);
        $to = $this->vertexId($output, $toLabel);
        $arrow = $edgeLabel === null ? '-->' : '-->|"' . $edgeLabel . '"|';

        $this->assertStringContainsString("{$from} {$arrow} {$to}", $output);
    }

    protected function vertexId(string $output, string $label): string
    {
        $matched = preg_match(
            '/^\s+(\w+)(?:\[+|\{)"' . preg_quote($label, '/') . '"/m',
            $output,
            $matches,
        );

        $this->assertSame(1, $matched, "Missing graph vertex: {$label}");

        return $matches[1];
    }
}
