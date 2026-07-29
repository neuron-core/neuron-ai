<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Agent\Middleware;

use NeuronAI\Agent\Agent;
use NeuronAI\Agent\Nodes\InferenceNode;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Testing\FakeMiddleware;
use NeuronAI\Tests\Stubs\StructuredOutput\User;
use PHPUnit\Framework\TestCase;

/**
 * Proves that middleware registered against the shared InferenceNode base class
 * fires in all three Agent execution modes (chat / stream / structured), which
 * compose three sibling node classes. Without the InferenceNode base +
 * subclass-aware matching, such middleware would only fire in one mode.
 */
class InferenceNodeMiddlewareTest extends TestCase
{
    public function test_inference_middleware_fires_in_chat_mode(): void
    {
        $this->assertInferenceMiddlewareFires(
            fn (Agent $agent): \NeuronAI\Chat\Messages\Message => $agent->chat(new UserMessage('Hi'))->getMessage()
        );
    }

    public function test_inference_middleware_fires_in_stream_mode(): void
    {
        $this->assertInferenceMiddlewareFires(
            fn (Agent $agent): \NeuronAI\Agent\AgentState => $agent->stream(new UserMessage('Hi'))->run()
        );
    }

    public function test_inference_middleware_fires_in_structured_mode(): void
    {
        $provider = new FakeAIProvider(new AssistantMessage('{"name": "Alice"}'));

        $middleware = FakeMiddleware::make();

        $agent = Agent::make();
        $agent->setAiProvider($provider);
        $agent->addMiddleware(InferenceNode::class, $middleware);

        $user = $agent->structured(new UserMessage('Generate a user'), User::class);

        $this->assertInstanceOf(User::class, $user);
        $middleware->assertBeforeCalledTimes(1);
        $middleware->assertAfterCalledTimes(1);
        $middleware->assertBeforeCalledForNode(InferenceNode::class);
    }

    /**
     * @param callable(Agent): mixed $run
     */
    private function assertInferenceMiddlewareFires(callable $run): void
    {
        $provider = new FakeAIProvider(new AssistantMessage('Hello!'));

        $middleware = FakeMiddleware::make();

        $agent = Agent::make();
        $agent->setAiProvider($provider);
        $agent->addMiddleware(InferenceNode::class, $middleware);

        $run($agent);

        $middleware->assertBeforeCalledTimes(1);
        $middleware->assertAfterCalledTimes(1);
        $middleware->assertBeforeCalledForNode(InferenceNode::class);
    }
}
