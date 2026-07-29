<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Agent\Middleware;

use NeuronAI\Agent\Agent;
use NeuronAI\Agent\Nodes\ChatNode;
use NeuronAI\Agent\Nodes\InferenceNode;
use NeuronAI\Agent\Nodes\StreamingNode;
use NeuronAI\Agent\Nodes\StructuredOutputNode;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Testing\FakeMiddleware;
use NeuronAI\Tests\Stubs\StructuredOutput\User;
use PHPUnit\Framework\TestCase;

class InferenceNodeMiddlewareTest extends TestCase
{
    public function test_inference_node_middleware_fires_in_chat_mode(): void
    {
        $middleware = FakeMiddleware::make();

        $agent = Agent::make();
        $agent->setAiProvider(new FakeAIProvider(new AssistantMessage('Hello!')));
        $agent->addMiddleware(InferenceNode::class, $middleware);

        $agent->chat(new UserMessage('Hi'))->getMessage();

        $middleware->assertBeforeCalledForNode(ChatNode::class);
        $middleware->assertAfterCalledForNode(ChatNode::class);
    }

    public function test_inference_node_middleware_fires_in_stream_mode(): void
    {
        $middleware = FakeMiddleware::make();

        $agent = Agent::make();
        $agent->setAiProvider(new FakeAIProvider(new AssistantMessage('Hello!')));
        $agent->addMiddleware(InferenceNode::class, $middleware);

        $agent->stream(new UserMessage('Hi'))->run();

        $middleware->assertBeforeCalledForNode(StreamingNode::class);
        $middleware->assertAfterCalledForNode(StreamingNode::class);
    }

    public function test_inference_node_middleware_fires_in_structured_mode(): void
    {
        $middleware = FakeMiddleware::make();

        $agent = Agent::make();
        $agent->setAiProvider(new FakeAIProvider(new AssistantMessage('{"name": "Alice"}')));
        $agent->addMiddleware(InferenceNode::class, $middleware);

        $agent->structured(new UserMessage('Generate a user'), User::class);

        $middleware->assertBeforeCalledForNode(StructuredOutputNode::class);
        $middleware->assertAfterCalledForNode(StructuredOutputNode::class);
    }

    public function test_middleware_on_a_concrete_mode_node_covers_only_that_mode(): void
    {
        $middleware = FakeMiddleware::make();

        $agent = Agent::make();
        $agent->setAiProvider(new FakeAIProvider(new AssistantMessage('Hello!')));
        $agent->addMiddleware(ChatNode::class, $middleware);

        $agent->stream(new UserMessage('Hi'))->run();

        $middleware->assertNotCalled();
    }
}
