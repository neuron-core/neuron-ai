<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Support;

use NeuronAI\Agent\AgentResources;
use NeuronAI\Chat\History\ChatHistory;
use NeuronAI\Chat\History\InMemoryMessageStore;
use NeuronAI\Chat\Messages\SystemMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Tools\ProviderToolInterface;
use NeuronAI\Tools\ToolInterface;
use NeuronAI\Tools\ToolRegistry;

/** The resources an agent node receives, for tests that run nodes directly. */
final class AgentResourcesFactory
{
    /**
     * @param array<ToolInterface|ProviderToolInterface> $tools
     */
    public static function make(
        array $tools = [],
        ?ChatHistory $history = null,
        ?AIProviderInterface $provider = null,
        SystemMessage|string $instructions = 'test instructions',
    ): AgentResources {
        return new AgentResources(
            $provider ?? new FakeAIProvider(),
            $history ?? new ChatHistory(new InMemoryMessageStore(), 'thread'),
            $instructions instanceof SystemMessage ? $instructions : new SystemMessage($instructions),
            new ToolRegistry($tools),
        );
    }

    /**
     * The history a node after inference finds: the turn's question is
     * committed before the model's tool calls reach the tool node.
     */
    public static function askedHistory(): ChatHistory
    {
        $history = new ChatHistory(new InMemoryMessageStore(), 'thread');
        $history->addMessage(new UserMessage('test question'));

        return $history;
    }
}
