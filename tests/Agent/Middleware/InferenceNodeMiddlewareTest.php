<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Agent\Middleware;

use NeuronAI\Agent\Agent;
use NeuronAI\Agent\Nodes\InferenceNode;
use NeuronAI\Agent\Nodes\ToolNode;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Testing\FakeMiddleware;
use NeuronAI\Tests\Stubs\StructuredOutput\User;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use PHPUnit\Framework\TestCase;

class InferenceNodeMiddlewareTest extends TestCase
{
    public function test_inference_node_middleware_fires_in_chat_mode(): void
    {
        $provider = new FakeAIProvider(new AssistantMessage('Hello!'));
        $middleware = FakeMiddleware::make();

        $agent = Agent::make();
        $agent->setAiProvider($provider);
        $agent->addMiddleware(InferenceNode::class, $middleware);

        $agent->chat(new UserMessage('Hi'))->getMessage();

        $middleware->assertBeforeCalledForNode(InferenceNode::class);
        $middleware->assertAfterCalledForNode(InferenceNode::class);
        $middleware->assertBeforeCalledTimes(1);
        $middleware->assertAfterCalledTimes(1);
    }

    public function test_inference_node_middleware_fires_in_stream_mode(): void
    {
        $provider = new FakeAIProvider(new AssistantMessage('Hello world'));
        $provider->setStreamChunkSize(5);
        $middleware = FakeMiddleware::make();

        $agent = Agent::make();
        $agent->setAiProvider($provider);
        $agent->addMiddleware(InferenceNode::class, $middleware);

        $handler = $agent->stream(new UserMessage('Hi'));

        // Consume the stream so the node completes and after() fires.
        foreach ($handler->events() as $event) {
            if ($event instanceof TextChunk) {
                continue;
            }
        }
        $handler->run();

        $middleware->assertBeforeCalledForNode(InferenceNode::class);
        $middleware->assertAfterCalledForNode(InferenceNode::class);
    }

    public function test_inference_node_middleware_fires_in_structured_mode(): void
    {
        $provider = new FakeAIProvider(new AssistantMessage('{"name": "Alice"}'));
        $middleware = FakeMiddleware::make();

        $agent = Agent::make();
        $agent->setAiProvider($provider);
        $agent->addMiddleware(InferenceNode::class, $middleware);

        $user = $agent->structured(new UserMessage('Generate a user'), User::class);

        $this->assertInstanceOf(User::class, $user);
        $middleware->assertBeforeCalledForNode(InferenceNode::class);
        $middleware->assertAfterCalledForNode(InferenceNode::class);
    }

    public function test_inference_node_middleware_does_not_fire_on_tool_node(): void
    {
        $searchTool = Tool::make('search', 'Search the web')
            ->addProperty(new ToolProperty('query', PropertyType::STRING, 'Search query', true))
            ->setCallable(fn (string $query): string => "Results for: {$query}");

        // First response triggers a tool call (runs ToolNode), second resolves it.
        $provider = new FakeAIProvider(
            new ToolCallMessage(null, [
                (clone $searchTool)->setCallId('call_1')->setInputs(['query' => 'PHP']),
            ]),
            new AssistantMessage('Done.'),
        );

        $middleware = FakeMiddleware::make();

        $agent = Agent::make();
        $agent->setAiProvider($provider);
        $agent->addTool($searchTool);
        $agent->addMiddleware(InferenceNode::class, $middleware);

        $agent->chat(new UserMessage('Search'))->getMessage();

        // The middleware must fire on the inference node(s) but never on ToolNode.
        $middleware->assertBeforeCalledForNode(InferenceNode::class);

        foreach ($middleware->getBeforeRecords() as $record) {
            $this->assertNotInstanceOf(
                ToolNode::class,
                $record->node,
                'InferenceNode-targeted middleware must not fire on ToolNode.'
            );
        }
        foreach ($middleware->getAfterRecords() as $record) {
            $this->assertNotInstanceOf(ToolNode::class, $record->node);
        }
    }
}
