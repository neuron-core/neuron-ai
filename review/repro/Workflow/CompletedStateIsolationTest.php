<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Streaming;

use NeuronAI\Tests\Workflow\Stub\NodeOne;
use NeuronAI\Tests\Workflow\Stub\NodeThree;
use NeuronAI\Tests\Workflow\Stub\NodeTwo;
use NeuronAI\Workflow\Streaming\Channel\CallbackChannel;
use NeuronAI\Workflow\Streaming\Channel\StreamingChannelInterface;
use NeuronAI\Workflow\Workflow;
use NeuronAI\Workflow\WorkflowState;
use PHPUnit\Framework\TestCase;

class CompletedStateIsolationTest extends TestCase
{
    public function test_a_channel_cannot_change_the_returned_state_through_the_completion_notification(): void
    {
        $received = null;
        $channel = new CallbackChannel(onCompleted: function (WorkflowState $state, string $workflowId) use (&$received): void {
            $received = $state;
            $state->set('changed_by_channel', true);
        });

        $state = Workflow::make()
            ->addNodes([new NodeOne(), new NodeTwo(), new NodeThree()])
            ->setChannel(fn (): StreamingChannelInterface => $channel)
            ->run();

        $this->assertInstanceOf(WorkflowState::class, $received);
        $this->assertTrue($received->has('changed_by_channel'));
        $this->assertFalse($state->has('changed_by_channel'));
    }
}
