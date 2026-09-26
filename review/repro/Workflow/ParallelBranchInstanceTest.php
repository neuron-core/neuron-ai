<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Exporter;

use InvalidArgumentException;
use NeuronAI\Tests\Workflow\Executor\Stub\DocumentParallelEvent;
use NeuronAI\Tests\Workflow\Executor\Stub\TextProcessEvent;
use NeuronAI\Tests\Workflow\Exporter\Stub\DescribedNode;
use NeuronAI\Workflow\Events\StartEvent;
use NeuronAI\Workflow\Exporter\ParallelTransition;
use NeuronAI\Workflow\Exporter\WorkflowGraphBuilder;
use PHPUnit\Framework\TestCase;

class ParallelBranchInstanceTest extends TestCase
{
    public function test_an_event_instance_as_a_branch_is_rejected_with_the_validation_error(): void
    {
        $node = new DescribedNode([new ParallelTransition(DocumentParallelEvent::class, ['text' => new TextProcessEvent()])]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must implement NeuronAI\Workflow\Events\Event');

        (new WorkflowGraphBuilder())->build(StartEvent::class, [StartEvent::class => $node]);
    }
}
