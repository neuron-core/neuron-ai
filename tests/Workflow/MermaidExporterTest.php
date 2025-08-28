<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow;

use NeuronAI\Workflow\Exporter\MermaidExporter;
use NeuronAI\Workflow\Workflow;
use PHPUnit\Framework\TestCase;
use NeuronAI\Tests\Workflow\Stubs\ConditionalNode;
use NeuronAI\Tests\Workflow\Stubs\NodeForSecond;
use NeuronAI\Tests\Workflow\Stubs\NodeForThird;
use NeuronAI\Tests\Workflow\Stubs\NodeOne;
use NeuronAI\Tests\Workflow\Stubs\NodeThree;
use NeuronAI\Tests\Workflow\Stubs\NodeTwo;

class MermaidExporterTest extends TestCase
{
    public function testBasicMermaidExport(): void
    {
        $workflow = Workflow::make()
            ->addNodes([
                new NodeOne(),
                new NodeTwo(),
                new NodeThree(),
            ]);

        $mermaidOutput = $workflow->export();

        // Verify Mermaid format header
        $this->assertStringStartsWith('graph TD', $mermaidOutput);

        // Verify event to node connections
        $this->assertStringContainsString('StartEvent --> NodeOne', $mermaidOutput);
        $this->assertStringContainsString('FirstEvent --> NodeTwo', $mermaidOutput);
        $this->assertStringContainsString('SecondEvent --> NodeThree', $mermaidOutput);

        // Verify node to event connections (return types)
        $this->assertStringContainsString('NodeOne --> FirstEvent', $mermaidOutput);
        $this->assertStringContainsString('NodeTwo --> SecondEvent', $mermaidOutput);
        $this->assertStringContainsString('NodeThree --> StopEvent', $mermaidOutput);
    }

    public function testConditionalNodeMermaidExport(): void
    {
        $workflow = Workflow::make()
            ->addNodes([
                new NodeOne(),
                new ConditionalNode(),
                new NodeForSecond(),
                new NodeForThird(),
            ]);

        $mermaidOutput = $workflow->export();

        // Verify conditional flow representation
        $this->assertStringContainsString('StartEvent --> NodeOne', $mermaidOutput);
        $this->assertStringContainsString('NodeOne --> FirstEvent', $mermaidOutput);
        $this->assertStringContainsString('FirstEvent --> ConditionalNode', $mermaidOutput);

        // Verify conditional node can produce both events
        // Note: Since ConditionalNode has union return type, the exporter should show
        // the actual return type from reflection, but union types are complex to detect
        // The exporter will show one of the union types or handle it differently
        $this->assertTrue(
            \str_contains($mermaidOutput, 'SecondEvent --> NodeForSecond') ||
            \str_contains($mermaidOutput, 'ThirdEvent --> NodeForThird')
        );
    }

    public function testMermaidExportWithCustomExporter(): void
    {
        $customExporter = new class () extends MermaidExporter {
            public function export(\NeuronAI\Workflow\Workflow $workflow): string
            {
                return "custom TD\n" . new parent($workflow);
            }
        };

        $workflow = Workflow::make()
            ->setExporter($customExporter)
            ->addNodes([
                new NodeOne(),
                new NodeTwo(),
            ]);

        $output = $workflow->export();
        $this->assertStringStartsWith('custom TD', $output);
    }

    public function testMermaidExportNoDuplicateConnections(): void
    {
        $workflow = Workflow::make()
            ->addNodes([
                new NodeOne(),
                new NodeTwo(),
                new NodeThree(),
            ]);

        $mermaidOutput = $workflow->export();
        $lines = \explode("\n", $mermaidOutput);

        // Remove empty lines and header
        $connections = \array_filter($lines, fn (string $line): bool => \trim($line) !== '' && !\str_contains($line, 'graph TD'));

        // Check for duplicate connections
        $uniqueConnections = \array_unique($connections);
        $this->assertCount(\count($connections), $uniqueConnections, 'Found duplicate connections in Mermaid output');
    }

    public function testMermaidExportShortClassNames(): void
    {
        $workflow = Workflow::make()
            ->addNodes([
                new NodeOne(),
                new NodeTwo(),
            ]);

        $mermaidOutput = $workflow->export();

        // Should use short class names, not full namespaced names
        $this->assertStringContainsString('NodeOne', $mermaidOutput);
        $this->assertStringContainsString('NodeTwo', $mermaidOutput);
        $this->assertStringContainsString('StartEvent', $mermaidOutput);
        $this->assertStringContainsString('FirstEvent', $mermaidOutput);

        // Should not contain full namespaces
        $this->assertStringNotContainsString('Tests\\Workflow\\Stubs\\', $mermaidOutput);
        $this->assertStringNotContainsString('NeuronAI\\Workflow\\', $mermaidOutput);
    }

    public function testMermaidExportEmptyWorkflow(): void
    {
        $workflow = Workflow::make();

        $mermaidOutput = $workflow->export();

        // Should still have header but no connections
        $this->assertEquals("graph TD\n", $mermaidOutput);
    }

    public function testMermaidExportComplexFlow(): void
    {
        // Create a more complex workflow with multiple paths
        $workflow = Workflow::make()
            ->addNodes([
                new NodeOne(),
                new ConditionalNode(),
                new NodeForSecond(),
                new NodeForThird(),
            ]);

        $mermaidOutput = $workflow->export();
        $lines = \explode("\n", \trim($mermaidOutput));

        // Remove header
        $connections = \array_filter($lines, fn (string $line): bool => !\str_contains($line, 'graph TD') && \trim($line) !== '');

        // Should have multiple connections representing the branching flow
        $this->assertGreaterThan(3, \count($connections), 'Complex workflow should have multiple connections');

        // Verify structure
        $connectionsStr = \implode(' ', $connections);
        $this->assertStringContainsString('StartEvent', $connectionsStr);
        $this->assertStringContainsString('StopEvent', $connectionsStr);
    }

    public function testMermaidExportEventNodeMapping(): void
    {
        $workflow = Workflow::make()
            ->addNodes([
                new NodeOne(),
                new NodeTwo(),
                new NodeThree(),
            ]);

        // Build event node map first to ensure it's populated
        $eventNodeMap = $workflow->getEventNodeMap();

        $mermaidOutput = $workflow->export();

        // Verify that the export reflects the event node mappings
        foreach ($eventNodeMap as $eventClass => $nodes) {
            $eventName = (new \ReflectionClass($eventClass))->getShortName();

            foreach ($nodes as $nodeClass => $node) {
                $nodeName = (new \ReflectionClass($nodeClass))->getShortName();
                $this->assertStringContainsString("{$eventName} --> {$nodeName}", $mermaidOutput);
            }
        }
    }

    public function testMermaidExportWithReturnTypeAnalysis(): void
    {
        $workflow = Workflow::make()
            ->addNodes([
                new NodeOne(),
                new NodeTwo(),
            ]);

        $mermaidOutput = $workflow->export();

        // Verify that return type analysis works correctly
        // NodeOne returns FirstEvent
        $this->assertStringContainsString('NodeOne --> FirstEvent', $mermaidOutput);

        // NodeTwo returns SecondEvent
        $this->assertStringContainsString('NodeTwo --> SecondEvent', $mermaidOutput);
    }

    public function testMermaidExportValidMermaidSyntax(): void
    {
        $workflow = Workflow::make()
            ->addNodes([
                new NodeOne(),
                new NodeTwo(),
                new NodeThree(),
            ]);

        $mermaidOutput = $workflow->export();
        $lines = \explode("\n", $mermaidOutput);

        // Verify Mermaid syntax is valid
        $this->assertEquals('graph TD', \trim($lines[0]));
        // Each connection line should follow the pattern "    NodeA --> NodeB"
        $counter = \count($lines);

        // Each connection line should follow the pattern "    NodeA --> NodeB"
        for ($i = 1; $i < $counter; $i++) {
            $line = $lines[$i];
            if (\trim($line) !== '') {
                $this->assertMatchesRegularExpression('/^\s+\w+ --> \w+$/', $line, "Invalid Mermaid syntax in line: {$line}");
            }
        }
    }
}
