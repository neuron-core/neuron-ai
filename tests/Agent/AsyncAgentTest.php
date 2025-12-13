<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Agent;

use Amp\Future;
use NeuronAI\Agent\Agent;
use NeuronAI\Agent\AgentState;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Providers\Anthropic\Anthropic;
use NeuronAI\Providers\HttpClient\AmpHttpClient;
use NeuronAI\Providers\HttpClient\GuzzleHttpClient;
use NeuronAI\Workflow\Async\AmpWorkflowExecutor;
use NeuronAI\Workflow\WorkflowState;
use PHPUnit\Framework\TestCase;

use function Amp\async;
use function Amp\delay;
use function microtime;
use function getenv;

/**
 * Tests demonstrating async agent workflows using custom HTTP clients.
 *
 * This test showcases two clean usage patterns:
 * 1. Default - provider handles everything
 * 2. Custom client - for async or special HTTP behavior
 */
class AsyncAgentTest extends TestCase
{
    /**
     * Pattern 1: Default usage (no changes from previous versions).
     *
     * The provider automatically creates a Guzzle client with all necessary
     * configuration (API key, base URI, headers).
     */
    public function testDefaultPattern(): void
    {
        $apiKey = getenv('ANTHROPIC_API_KEY');
        if (!$apiKey) {
            $this->markTestSkipped('ANTHROPIC_API_KEY not set');
        }

        // Dead simple - provider handles everything
        $provider = new Anthropic(
            key: $apiKey,
            model: 'claude-3-5-sonnet-20241022'
        );

        $agent = Agent::make()->setAiProvider($provider);

        $handler = $agent->chat(new UserMessage('Say hello in one word'));
        $result = $handler->getResult();

        $this->assertInstanceOf(WorkflowState::class, $result);
    }

    /**
     * Pattern 2a: Customize default Guzzle client using with*() methods.
     *
     * Pre-configure a client with custom settings, provider adds auth/URI.
     */
    public function testCustomizedGuzzlePattern(): void
    {
        $apiKey = getenv('ANTHROPIC_API_KEY');
        if (!$apiKey) {
            $this->markTestSkipped('ANTHROPIC_API_KEY not set');
        }

        // Pre-configure Guzzle client with custom timeout/headers
        $httpClient = (new GuzzleHttpClient())
            ->withTimeout(60.0)
            ->withHeaders(['X-Custom-Header' => 'my-value']);

        $provider = new Anthropic(
            key: $apiKey,
            model: 'claude-3-5-sonnet-20241022',
            httpClient: $httpClient  // Provider adds auth/URI on top
        );

        $agent = Agent::make()->setAiProvider($provider);

        $handler = $agent->chat(new UserMessage('Say hello in one word'));
        $result = $handler->getResult();

        $this->assertInstanceOf(WorkflowState::class, $result);
    }

    /**
     * Pattern 2b: Use async HTTP client for concurrent workflows.
     *
     * Pass empty async client, provider configures it with auth/URI.
     */
    public function testAsyncClientPattern(): void
    {
        $apiKey = getenv('ANTHROPIC_API_KEY');
        if (!$apiKey) {
            $this->markTestSkipped('ANTHROPIC_API_KEY not set');
        }

        // Create empty Amp client - provider will configure it
        $httpClient = new AmpHttpClient();

        $provider = new Anthropic(
            key: $apiKey,
            model: 'claude-3-5-sonnet-20241022',
            httpClient: $httpClient  // Provider configures this internally
        );

        $agent = Agent::make()->setAiProvider($provider);

        // Execute within async workflow
        $executor = new AmpWorkflowExecutor();
        $handler = $agent->chat(new UserMessage('Say hello in one word'));
        $future = $executor->execute($handler);

        /** @var AgentState $result */
        $result = $future->await();

        $this->assertInstanceOf(WorkflowState::class, $result);
        $this->assertNotEmpty($result->getChatHistory()->getMessages());
    }

    /**
     * Demonstrates concurrent execution of multiple agents.
     *
     * This pattern enables true parallelism - multiple agents can process
     * requests concurrently without blocking each other.
     */
    public function testConcurrentAgentExecution(): void
    {
        $apiKey = getenv('ANTHROPIC_API_KEY');
        if (!$apiKey) {
            $this->markTestSkipped('ANTHROPIC_API_KEY not set');
        }

        // Create empty Amp client - provider will configure it
        $httpClient = new AmpHttpClient();

        $provider = new Anthropic(
            key: $apiKey,
            model: 'claude-3-5-sonnet-20241022',
            httpClient: $httpClient
        );

        // Create three agents with different prompts
        $agent1 = Agent::make()->setAiProvider($provider)->setInstructions('Count to 3');
        $agent2 = Agent::make()->setAiProvider($provider)->setInstructions('Name 3 colors');
        $agent3 = Agent::make()->setAiProvider($provider)->setInstructions('Name 3 animals');

        $executor = new AmpWorkflowExecutor();

        $startTime = microtime(true);

        // Execute all three agents concurrently
        $future1 = $executor->execute($agent1->chat(new UserMessage('Go')));
        $future2 = $executor->execute($agent2->chat(new UserMessage('Go')));
        $future3 = $executor->execute($agent3->chat(new UserMessage('Go')));

        // Wait for all to complete
        [$result1, $result2, $result3] = Future\await([$future1, $future2, $future3]);

        $duration = microtime(true) - $startTime;

        // All three should complete
        $this->assertInstanceOf(WorkflowState::class, $result1);
        $this->assertInstanceOf(WorkflowState::class, $result2);
        $this->assertInstanceOf(WorkflowState::class, $result3);

        // Concurrent execution should be significantly faster than sequential
        $this->assertLessThan(15, $duration, 'Concurrent execution should complete reasonably fast');
    }

    /**
     * Demonstrates mixing async HTTP operations with other async operations
     * like delays, database queries, file I/O, etc.
     */
    public function testMixedAsyncOperations(): void
    {
        $apiKey = getenv('ANTHROPIC_API_KEY');
        if (!$apiKey) {
            $this->markTestSkipped('ANTHROPIC_API_KEY not set');
        }

        $httpClient = new AmpHttpClient();

        $provider = new Anthropic(
            key: $apiKey,
            model: 'claude-3-5-sonnet-20241022',
            httpClient: $httpClient
        );

        $agent = Agent::make()->setAiProvider($provider);

        $executor = new AmpWorkflowExecutor();

        // Start agent execution
        $agentFuture = $executor->execute($agent->chat(new UserMessage('Hello')));

        // Perform other async operations concurrently
        $delayFuture = async(function (): string {
            delay(0.5);
            return 'delay_completed';
        });

        // Both should complete, with delay not blocking agent
        [$agentResult, $delayResult] = Future\await([$agentFuture, $delayFuture]);

        $this->assertInstanceOf(WorkflowState::class, $agentResult);
        $this->assertEquals('delay_completed', $delayResult);
    }
}
