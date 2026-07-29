<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Chat\History;

use NeuronAI\Chat\History\AbstractChatHistory;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Tools\ApprovalState;
use NeuronAI\Tools\ToolDefinition;
use PHPUnit\Framework\TestCase;

/**
 * Exposes the protected deserialization machinery for round-trip testing.
 */
class TestableChatHistory extends AbstractChatHistory
{
    /**
     * @param array<int, array<string, mixed>> $messages
     * @return Message[]
     */
    public function publicDeserialize(array $messages): array
    {
        return $this->deserializeMessages($messages);
    }
}

class ApprovalSerializationTest extends TestCase
{
    public function test_round_trip_preserves_approval_state_and_reason(): void
    {
        $pending = ToolDefinition::make('pending_tool', 'd')
            ->setCallId('c1')
            ->setInputs(['a' => 1]);
        $pending->setApprovalState(ApprovalState::Pending);

        $rejected = ToolDefinition::make('rejected_tool', 'd')
            ->setCallId('c2')
            ->setInputs(['b' => 2]);
        $rejected->setApprovalState(ApprovalState::Rejected, 'too risky');

        $message = new ToolCallMessage(tools: [$pending, $rejected]);

        $history = new TestableChatHistory();
        $restored = $history->publicDeserialize([$message->jsonSerialize()]);

        $this->assertCount(1, $restored);
        $this->assertInstanceOf(ToolCallMessage::class, $restored[0]);

        $restoredMessage = $restored[0];
        $this->assertInstanceOf(ToolCallMessage::class, $restoredMessage);
        $tools = $restoredMessage->getTools();
        $this->assertCount(2, $tools);

        $this->assertEquals(ApprovalState::Pending, $tools[0]->getApprovalState());
        $this->assertNull($tools[0]->getApprovalReason());

        $this->assertEquals(ApprovalState::Rejected, $tools[1]->getApprovalState());
        $this->assertSame('too risky', $tools[1]->getApprovalReason());
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

        $restored = (new TestableChatHistory())->publicDeserialize([$legacyMessage]);

        $this->assertCount(1, $restored);
        $this->assertInstanceOf(ToolCallMessage::class, $restored[0]);

        $restoredMessage = $restored[0];
        $this->assertInstanceOf(ToolCallMessage::class, $restoredMessage);
        $tools = $restoredMessage->getTools();
        $this->assertCount(1, $tools);
        $this->assertNull($tools[0]->getApprovalState());
    }
}
