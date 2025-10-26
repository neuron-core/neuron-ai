<?php

declare(strict_types=1);

namespace NeuronAI\Agent\Nodes;

use NeuronAI\Agent\AgentState;
use NeuronAI\Agent\Events\AIInferenceEvent;
use NeuronAI\Agent\Events\ToolCallEvent;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Observability\Events\InferenceStart;
use NeuronAI\Observability\Events\InferenceStop;
use NeuronAI\Observability\Observable;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Workflow\Events\StopEvent;
use NeuronAI\Workflow\Node;

/**
 * Node responsible for making requests to the AI provider.
 *
 * Receives an AIInferenceEvent containing instructions and tools that can be
 * modified by middleware before the actual inference call is made.
 */
class ChatNode extends Node
{
    use Observable;

    public function __construct(
        protected AIProviderInterface $provider,
    ) {
    }

    public function __invoke(AIInferenceEvent $event, AgentState $state): StopEvent|ToolCallEvent
    {
        $chatHistory = $state->getChatHistory();

        $this->notify(
            'inference-start',
            new InferenceStart($chatHistory->getLastMessage())
        );

        // Make the AI provider call using configuration from the event
        $response = $this->provider
            ->systemPrompt($event->instructions)
            ->setTools($event->tools)
            ->chat($chatHistory->getMessages());

        // Add the response to chat history
        $chatHistory->addMessage($response);

        $this->notify(
            'inference-stop',
            new InferenceStop($chatHistory->getLastMessage(), $response)
        );

        // If the response is a tool call, route to tool execution
        if ($response instanceof ToolCallMessage) {
            return new ToolCallEvent($response, $event);
        }

        return new StopEvent();
    }
}
