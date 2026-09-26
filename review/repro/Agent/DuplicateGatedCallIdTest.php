<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Agent;

use NeuronAI\Agent\Agent;
use NeuronAI\Chat\History\InMemoryMessageStore;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Exceptions\WorkflowException;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Tests\Agent\Stub\CountingTool;
use NeuronAI\Tools\ToolCall;
use NeuronAI\Workflow\Persistence\InMemoryPersistence;
use PHPUnit\Framework\TestCase;

use function end;

class DuplicateGatedCallIdTest extends TestCase
{
    public function test_a_refused_approval_batch_leaves_no_dangling_tool_call(): void
    {
        $agent = Agent::make(workflowId: 'duplicate-gated')
            ->setPersistence(new InMemoryPersistence())
            ->setMessageStore(new InMemoryMessageStore())
            ->setAiProvider(new FakeAIProvider(
                new ToolCallMessage(null, [
                    new ToolCall('lookup', 'dup', ['query' => 'PHP']),
                    new ToolCall('lookup', 'dup', ['query' => 'Rust']),
                ]),
            ))
            ->addTool((new CountingTool())->requireApproval());

        try {
            $agent->chat(new UserMessage('Look up both'))->getMessage();
            $this->fail('A batch of gated calls sharing a call id must be refused.');
        } catch (WorkflowException $e) {
            $this->assertStringContainsString('Duplicate approval action id "dup"', $e->getMessage());
        }

        $messages = $agent->getChatHistory()->getMessages();
        $this->assertNotInstanceOf(ToolCallMessage::class, end($messages));

        // No approval request exists to settle, so abandon() must free the thread.
        $this->assertTrue($agent->abandon());
    }
}
