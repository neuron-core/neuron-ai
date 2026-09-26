<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Exporter;

use NeuronAI\Workflow\Events\StartEvent;
use NeuronAI\Workflow\Events\StopEvent;
use NeuronAI\Workflow\Exporter\MermaidExporter;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\Workflow;
use NeuronAI\Workflow\WorkflowState;
use PHPUnit\Framework\TestCase;

class AnonymousNodeExportTest extends TestCase
{
    public function test_an_anonymous_node_label_leaks_neither_a_null_byte_nor_the_source_path(): void
    {
        $node = new class () extends Node {
            public function __invoke(StartEvent $event, WorkflowState $state): StopEvent
            {
                return new StopEvent();
            }
        };

        foreach ([Workflow::make()->addNode($node)->export(), Workflow::make()->addNode($node)->setExporter(new MermaidExporter())->export()] as $output) {
            $this->assertStringNotContainsString("\0", $output);
            $this->assertStringNotContainsString(__FILE__, $output);
            $this->assertStringContainsString('Node@anonymous', $output);
        }
    }
}
