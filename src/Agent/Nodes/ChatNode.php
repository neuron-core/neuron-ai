<?php

declare(strict_types=1);

namespace NeuronAI\Agent\Nodes;

use NeuronAI\Agent\AgentState;
use NeuronAI\Agent\Events\AIResponseEvent;
use NeuronAI\Observability\Events\InferenceStart;
use NeuronAI\Observability\Events\InferenceStop;
use NeuronAI\Observability\Observable;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Tools\ToolInterface;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\StartEvent;

/**
 * Node responsible for making requests to the AI provider.
 */
class ChatNode extends Node
{
    use Observable;

    /**
     * @param ToolInterface[] $tools
     */
    public function __construct(
        protected AIProviderInterface $provider,
        protected string $instructions,
        protected array $tools = []
    ) {
    }

    public function __invoke(StartEvent $event, AgentState $state): AIResponseEvent
    {
        $chatHistory = $state->getChatHistory();

        $this->notify(
            'inference-start',
            new InferenceStart($chatHistory->getLastMessage())
        );

        // Make the AI provider call
        $response = $this->provider
            ->systemPrompt($this->instructions)
            ->setTools($this->tools)
            ->chat($chatHistory->getMessages());

        $this->notify(
            'inference-stop',
            new InferenceStop($chatHistory->getLastMessage(), $response)
        );

        // Add response to chat history
        $chatHistory->addMessage($response);

        return new AIResponseEvent($response);
    }
}
