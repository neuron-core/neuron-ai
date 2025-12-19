<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Agent;

use Amp\Future;
use NeuronAI\Agent\Agent;
use NeuronAI\Agent\AgentState;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\HttpClient\AmpHttpClient;
use NeuronAI\Providers\Anthropic\Anthropic;
use NeuronAI\Workflow\WorkflowState;
use PHPUnit\Framework\TestCase;

use function Amp\async;
use function Amp\delay;
use function getenv;
use function microtime;

class AsyncAgentTest extends TestCase
{
    protected string $model = "claude-3-7-sonnet-20250219";

    protected string $key;

    protected function setUp(): void
    {
        if (!$this->key = getenv('ANTHROPIC_API_KEY')) {
            $this->markTestSkipped('ANTHROPIC_API_KEY not set');
        }
    }

    public function testAsyncClientPattern(): void
    {
        $provider = new Anthropic(
            key: $this->key,
            model: $this->model,
            httpClient: new AmpHttpClient(),
        );

        $agent = Agent::make()->setAiProvider($provider);

        // Execute within the async context
        $handler = $agent->chat(new UserMessage('Say hello in one word'));
        $future = async(fn () => $handler->run());

        /** @var AgentState $result */
        $result = $future->await();

        $this->assertInstanceOf(WorkflowState::class, $result);
        $this->assertInstanceOf(AssistantMessage::class, $result->getChatHistory()->getLastMessage());
    }

    public function testConcurrentAgentExecution(): void
    {
        $provider = new Anthropic(
            key: $this->key,
            model: $this->model,
            httpClient: new AmpHttpClient(),
        );

        // Create three agents with different prompts
        $agent1 = Agent::make()->setAiProvider($provider)->setInstructions('Count to 3');
        $agent2 = Agent::make()->setAiProvider($provider)->setInstructions('Name 3 colors');
        $agent3 = Agent::make()->setAiProvider($provider)->setInstructions('Name 3 animals');

        $startTime = microtime(true);

        // Execute all three agents concurrently
        $future1 = async(fn () => $agent1->chat(new UserMessage('Go'))->run());
        $future2 = async(fn () => $agent2->chat(new UserMessage('Go'))->run());
        $future3 = async(fn () => $agent3->chat(new UserMessage('Go'))->run());

        // Wait for all to complete
        [$result1, $result2, $result3] = Future\await([$future1, $future2, $future3]);

        $duration = microtime(true) - $startTime;

        // All three should complete
        $this->assertInstanceOf(AgentState::class, $result1);
        $this->assertInstanceOf(AgentState::class, $result2);
        $this->assertInstanceOf(AgentState::class, $result3);

        // Concurrent execution should be significantly faster than sequential
        $this->assertLessThan(4, $duration, 'Concurrent execution should complete in less than 4 seconds');
    }

    public function testMixedAsyncOperations(): void
    {
        $provider = new Anthropic(
            key: $this->key,
            model: $this->model,
            httpClient: new AmpHttpClient(),
        );

        $agent = Agent::make()->setAiProvider($provider);

        $agentFuture = async(fn () => $agent->chat(new UserMessage('Hello'))->run());

        // Perform other async operations concurrently
        $delayFuture = async(function (): string {
            delay(0.5);
            return 'delay_completed';
        });

        /** @var AgentState $agentResult */
        // Both should complete, with delay not blocking the agent
        [$agentResult, $delayResult] = Future\await([$agentFuture, $delayFuture]);

        $this->assertInstanceOf(AgentState::class, $agentResult);
        $this->assertEquals('delay_completed', $delayResult);
        $this->assertInstanceOf(AssistantMessage::class, $agentResult->getChatHistory()->getLastMessage());
    }
}
