<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Agent;

use NeuronAI\Chat\History\InMemoryMessageStore;
use NeuronAI\Exceptions\ToolException;
use NeuronAI\Tests\Agent\Stub\CountingTool;
use NeuronAI\Tests\Agent\Stub\DuplicateCallIdWeatherTool;
use NeuronAI\Tools\FrontendTool;
use NeuronAI\Tools\ToolCall;
use NeuronAI\Agent\Agent;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolInterface;
use NeuronAI\Workflow\Persistence\InMemoryPersistence;
use PHPUnit\Framework\TestCase;

use function end;

/**
 * Regression test: providers without per-call ids (Gemini historically reused the
 * tool name as callId) can hand the ToolNode parallel calls sharing a callId. The
 * durable memo key must still be unique per call, or the second call is silently
 * skipped and handed the first call's result. A call waiting for an approval or an
 * external result is matched to its reply by ID, so there the batch is refused.
 */
class DuplicateCallIdToolExecutionTest extends TestCase
{
    protected function setUp(): void
    {
        DuplicateCallIdWeatherTool::$executions = [];
    }

    public function test_parallel_calls_sharing_a_call_id_both_execute(): void
    {
        $registered = new DuplicateCallIdWeatherTool();

        $first = ToolCall::make($registered->getName(), 'get_weather', ['city' => 'Rome']);
        $second = ToolCall::make($registered->getName(), 'get_weather', ['city' => 'Milan']);

        $provider = new FakeAIProvider(
            new ToolCallMessage(null, [$first, $second]),
            new AssistantMessage('done'),
        );

        $agent = Agent::make()
            ->setAiProvider($provider)
            ->addTool($registered);

        $agent->chat(new UserMessage('Weather in Rome and Milan?'))->getMessage();

        $this->assertSame(['Rome', 'Milan'], DuplicateCallIdWeatherTool::$executions);

        $results = [];
        foreach ($agent->getChatHistory()->getMessages() as $message) {
            if ($message instanceof ToolResultMessage) {
                foreach ($message->getToolCalls() as $tool) {
                    $results[] = $tool->getResult();
                }
            }
        }

        $this->assertSame(
            ['Weather in Rome: sunny', 'Weather in Milan: sunny'],
            $results
        );
    }

    public function test_frontend_calls_sharing_a_call_id_are_refused_before_dispatch(): void
    {
        $agent = $this->agent(new FrontendTool('browser'), new ToolCallMessage(null, [
            new ToolCall('browser', 'dup', ['url' => 'https://a.example'], deferred: true),
            new ToolCall('browser', 'dup', ['url' => 'https://b.example'], deferred: true),
        ]));

        $this->assertRefused($agent, "Tool call browser needs a unique call ID to be approved or answered, but the provider returned 'dup' twice.");
    }

    public function test_approval_calls_sharing_a_call_id_are_refused_before_the_history_write(): void
    {
        $agent = $this->agent((new CountingTool())->requireApproval(), new ToolCallMessage(null, [
            new ToolCall('lookup', 'dup', ['query' => 'PHP']),
            new ToolCall('lookup', 'dup', ['query' => 'Rust']),
        ]));

        $this->assertRefused($agent, "Tool call lookup needs a unique call ID to be approved or answered, but the provider returned 'dup' twice.");
        $this->assertTrue($agent->abandon());
    }

    public function test_an_approval_call_without_a_call_id_is_refused(): void
    {
        $agent = $this->agent((new CountingTool())->requireApproval(), new ToolCallMessage(null, [
            new ToolCall('lookup', null, ['query' => 'PHP']),
        ]));

        $this->assertRefused($agent, 'Tool call lookup needs a unique call ID to be approved or answered, but the provider returned none.');
    }

    protected function agent(ToolInterface $tool, ToolCallMessage $response): Agent
    {
        return Agent::make(workflowId: 'thread')
            ->setPersistence(new InMemoryPersistence())
            ->setMessageStore(new InMemoryMessageStore())
            ->setAiProvider(new FakeAIProvider($response))
            ->addTool($tool);
    }

    protected function assertRefused(Agent $agent, string $message): void
    {
        try {
            $agent->chat(new UserMessage('Go'));
            $this->fail('The batch must be refused.');
        } catch (ToolException $e) {
            $this->assertSame($message, $e->getMessage());
        }

        $messages = $agent->getChatHistory()->getMessages();
        $this->assertNotInstanceOf(ToolCallMessage::class, end($messages), 'Nothing of the refused batch is written');
    }
}
