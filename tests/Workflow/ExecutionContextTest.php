<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow;

use NeuronAI\Tests\Workflow\Stub\FirstEvent;
use NeuronAI\Workflow\ExecutionContext;
use NeuronAI\Workflow\Executor\Ignition;
use PHPUnit\Framework\TestCase;

class ExecutionContextTest extends TestCase
{
    public function test_it_carries_the_identity_of_the_admitted_segment(): void
    {
        $context = new ExecutionContext('order-42', 'run_1', 3, new Ignition('run_1', new FirstEvent()));

        $this->assertSame('order-42', $context->workflowId);
        $this->assertSame('run_1', $context->runId);
        $this->assertSame(3, $context->executionAttempt);
    }

    public function test_the_start_event_is_captured_at_creation_and_every_read_is_detached(): void
    {
        $input = new FirstEvent('original');
        $context = new ExecutionContext('order-42', 'run_1', 1, new Ignition('run_1', $input));

        $input->message = 'changed by the caller';
        $first = $context->startEvent();
        $this->assertInstanceOf(FirstEvent::class, $first);
        $first->message = 'changed by a listener';
        $second = $context->startEvent();

        $this->assertInstanceOf(FirstEvent::class, $second);
        $this->assertSame('original', $second->message);
        $this->assertNotSame($first, $second);
    }
}
