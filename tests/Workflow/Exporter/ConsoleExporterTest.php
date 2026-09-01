<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Exporter;

use NeuronAI\Tests\Workflow\Executor\Stub\DocumentParallelProcessing;
use NeuronAI\Tests\Workflow\Executor\Stub\IgnitionStartEvent;
use NeuronAI\Tests\Workflow\Executor\Stub\IgnitionWaitNode;
use NeuronAI\Tests\Workflow\Executor\Stub\ImageProcessNode;
use NeuronAI\Tests\Workflow\Executor\Stub\MergeNode;
use NeuronAI\Tests\Workflow\Executor\Stub\TextProcessNode;
use NeuronAI\Workflow\Workflow;
use PHPUnit\Framework\TestCase;

class ConsoleExporterTest extends TestCase
{
    public function test_parallel_branches_are_connected_to_the_join(): void
    {
        $output = Workflow::make()
            ->addNodes([
                new DocumentParallelProcessing(),
                new TextProcessNode(),
                new ImageProcessNode(),
                new MergeNode(),
            ])
            ->export();

        $this->assertStringContainsString('DocumentParallelEvent split', $output);
        $this->assertStringContainsString('[text] ↓', $output);
        $this->assertStringContainsString('[image] ↓', $output);
        $this->assertStringContainsString('DocumentParallelEvent join', $output);
        $this->assertStringContainsString('MergeNode', $output);
        $this->assertStringNotContainsString('Orphaned Node', $output);
        $this->assertStringNotContainsString('Cycle detected', $output);
    }

    public function test_custom_start_event_is_the_graph_root(): void
    {
        $output = Workflow::make()
            ->setStartEvent(new IgnitionStartEvent())
            ->addNode(new IgnitionWaitNode())
            ->export();

        $this->assertStringContainsString('🏁 IgnitionStartEvent', $output);
        $this->assertStringContainsString('IgnitionWaitNode', $output);
        $this->assertStringNotContainsString('No StartEvent found', $output);
    }
}
