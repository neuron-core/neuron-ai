<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Chat\Messages;

use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Tools\ApprovalState;
use NeuronAI\Tools\ToolCall;
use PHPUnit\Framework\TestCase;

use function json_encode;

use const JSON_THROW_ON_ERROR;

class ToolMessagesTest extends TestCase
{
    public function test_a_tool_call_message_is_an_assistant_turn(): void
    {
        $message = new ToolCallMessage('Checking', [new ToolCall('lookup', 'call-1')]);

        $this->assertInstanceOf(AssistantMessage::class, $message);
        $this->assertSame('assistant', $message->getRole());
        $this->assertSame('Checking', $message->getContent());
    }

    public function test_a_tool_result_message_is_a_user_turn_without_content(): void
    {
        $message = new ToolResultMessage([(new ToolCall('lookup', 'call-1'))->setResult('found')]);

        $this->assertInstanceOf(UserMessage::class, $message);
        $this->assertSame('user', $message->getRole());
        $this->assertSame([], $message->getContentBlocks());
    }

    public function test_tool_call_message_serializes_its_calls(): void
    {
        $call = new ToolCall('lookup', 'call-1', ['query' => 'neuron'], 'Search the docs');
        $call->setApprovalState(ApprovalState::Pending)->setApprovalReason('Costs money');
        $message = (new ToolCallMessage(null, [$call]))->setId('msg_1');

        $this->assertSame([
            '__id' => 'msg_1',
            'role' => 'assistant',
            'content' => [],
            '__meta' => [],
            'type' => 'tool_call',
            'tools' => [[
                'callId' => 'call-1',
                'name' => 'lookup',
                'description' => 'Search the docs',
                'deferred' => false,
                'inputs' => ['query' => 'neuron'],
                'result' => null,
                'approval' => 'pending',
                'approvalReason' => 'Costs money',
                'rejectReason' => null,
            ]],
        ], $message->jsonSerialize());
    }

    public function test_tool_result_message_serializes_its_settled_calls(): void
    {
        $call = (new ToolCall('lookup', 'call-1', ['query' => 'neuron']))->setResult('found');
        $call->setApprovalState(ApprovalState::Rejected, 'Not allowed');
        $message = (new ToolResultMessage([$call]))->setId('msg_2');

        $serialized = $message->jsonSerialize();

        $this->assertSame('tool_call_result', $serialized['type']);
        $this->assertSame('user', $serialized['role']);
        $this->assertSame('msg_2', $serialized['__id']);
        $this->assertSame('found', $serialized['tools'][0]['result']);
        $this->assertSame('rejected', $serialized['tools'][0]['approval']);
        $this->assertSame('Not allowed', $serialized['tools'][0]['rejectReason']);
    }

    public function test_calls_are_exposed_in_their_order(): void
    {
        $first = new ToolCall('a', 'call-1');
        $second = new ToolCall('b', 'call-2');

        $this->assertSame([$first, $second], (new ToolCallMessage(null, [$first, $second]))->getToolCalls());
        $this->assertSame([$first, $second], (new ToolResultMessage([$first, $second]))->getToolCalls());
    }

    public function test_string_form_is_the_json_of_the_calls(): void
    {
        $call = (new ToolCall('lookup', 'call-1', ['query' => 'neuron']))->setResult('found');

        $expected = json_encode([$call], JSON_THROW_ON_ERROR);

        $this->assertSame($expected, (string) new ToolCallMessage(null, [$call]));
        $this->assertSame($expected, (string) new ToolResultMessage([$call]));
    }

    public function test_empty_inputs_serialize_as_a_json_object(): void
    {
        $message = new ToolCallMessage(null, [new ToolCall('ping', 'call-1')]);

        $this->assertStringContainsString('"inputs":{}', json_encode($message, JSON_THROW_ON_ERROR));
    }
}
