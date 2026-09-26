<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Agent;

use NeuronAI\Agent\Agent;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Tests\Agent\Stub\AgentSecretTool;
use NeuronAI\Tools\ToolCall;
use PHPUnit\Framework\TestCase;

/**
 * Demonstrates the doc mismatch: asserts the behaviour promised by
 * skills/neuron-tool/SKILL.md ("Hidden from LLM schema but still callable"),
 * which the framework intentionally does NOT implement. Not for the suite.
 */
class HiddenToolDocContractTest extends TestCase
{
    public function test_a_hidden_tool_is_still_callable_as_the_skill_doc_promises(): void
    {
        $provider = new FakeAIProvider(
            new ToolCallMessage(null, [ToolCall::make('secret', 'call_1', ['input' => 'x'])]),
            new AssistantMessage('done')
        );
        $agent = Agent::make()->setAiProvider($provider)->addTool((new AgentSecretTool())->visible(false));

        $message = $agent->chat(new UserMessage('Test'))->getMessage();

        // Fails on current code: ToolException "The tool secret is not registered on this agent: the call cannot be executed."
        $this->assertSame('done', $message->getContent());
    }
}
