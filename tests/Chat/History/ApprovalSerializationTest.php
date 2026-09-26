<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Chat\History;

use NeuronAI\Chat\Messages\MessageDeserializer;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Tools\ApprovalState;
use NeuronAI\Tools\ToolCall;
use PHPUnit\Framework\TestCase;

class ApprovalSerializationTest extends TestCase
{
    public function test_round_trip_preserves_approval_state_and_reason(): void
    {
        $pending = ToolCall::make('pending_tool', description: 'd')
            ->setCallId('c1')
            ->setInputs(['a' => 1]);
        $pending->setApprovalState(ApprovalState::Pending);
        $pending->setApprovalReason('This action is irreversible');

        $rejected = ToolCall::make('rejected_tool', description: 'd')
            ->setCallId('c2')
            ->setInputs(['b' => 2]);
        $rejected->setApprovalState(ApprovalState::Rejected, 'too risky');

        $message = new ToolCallMessage(tools: [$pending, $rejected]);

        $restored = (new MessageDeserializer())->deserialize($message->jsonSerialize());

        $this->assertInstanceOf(ToolCallMessage::class, $restored);
        $tools = $restored->getToolCalls();
        $this->assertCount(2, $tools);

        $this->assertSame(['c1', 'c2'], [$tools[0]->getCallId(), $tools[1]->getCallId()]);
        $this->assertSame(['a' => 1], $tools[0]->getInputs());
        $this->assertSame(ApprovalState::Pending, $tools[0]->getApprovalState());
        $this->assertNull($tools[0]->getRejectReason());
        $this->assertSame('This action is irreversible', $tools[0]->getApprovalReason());

        $this->assertSame(ApprovalState::Rejected, $tools[1]->getApprovalState());
        $this->assertSame('too risky', $tools[1]->getRejectReason());
        $this->assertNull($tools[1]->getApprovalReason());
    }

    public function test_legacy_shape_without_approval_key_loads_as_null(): void
    {
        $legacyMessage = [
            'type' => 'tool_call',
            'role' => 'assistant',
            'content' => null,
            'tools' => [
                [
                    'name' => 'legacy_tool',
                    'description' => 'old tool with no approval fields',
                    'parameters' => [],
                    'inputs' => [],
                    'callId' => 'c1',
                    'result' => null,
                ],
            ],
        ];

        $restored = (new MessageDeserializer())->deserialize($legacyMessage);

        $this->assertInstanceOf(ToolCallMessage::class, $restored);
        $tools = $restored->getToolCalls();
        $this->assertCount(1, $tools);
        $this->assertSame('legacy_tool', $tools[0]->getName());
        $this->assertNull($tools[0]->getApprovalState());
        $this->assertNull($tools[0]->getApprovalReason());
        $this->assertNull($tools[0]->getRejectReason());
        $this->assertFalse($tools[0]->hasResult());
    }
}
