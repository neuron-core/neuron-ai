<?php

declare(strict_types=1);

namespace NeuronAI\Agent\Nodes;

use Inspector\Exceptions\InspectorException;
use NeuronAI\Agent\AgentState;
use NeuronAI\Agent\ChatHistoryHelper;
use NeuronAI\Agent\Events\AIInferenceEvent;
use NeuronAI\Agent\Events\ToolCallEvent;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Observability\EventBus;
use NeuronAI\Observability\Events\InferenceStart;
use NeuronAI\Observability\Events\InferenceStop;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Workflow\Events\StopEvent;
use NeuronAI\Workflow\Node;

/**
 * Node responsible for making requests to the AI provider.
 *
 * Receives an AIInferenceEvent containing instructions and tools that middleware can
 * modify before the actual inference call is made.
 */
class ChatNode extends Node
{
    use ChatHistoryHelper;

    /**
     * @param Message[] $messages
     */
    public function __construct(
        protected AIProviderInterface $provider,
        protected array $messages
    ) {
    }

    /**
     * @throws InspectorException
     */
    public function __invoke(AIInferenceEvent $event, AgentState $state): StopEvent|ToolCallEvent
    {
        // Ensure initial messages are added to chat history only once
        $this->addInitialMessagesOnce($state, $this->messages);

        $chatHistory = $state->getChatHistory();
        $lastMessage = $chatHistory->getLastMessage();

        EventBus::emit(
            'inference-start',
            $this,
            new InferenceStart($lastMessage)
        );

        // Make the AI provider call using configuration from the event
        $response = $this->provider
            ->systemPrompt($event->instructions)
            ->setTools($event->tools)
            ->chat($chatHistory->getMessages());

        EventBus::emit(
            'inference-stop',
            $this,
            new InferenceStop($lastMessage, $response)
        );

        // Add the response to chat history
        $this->addToChatHistory($state, $response);

        // If the response is a tool call, route to tool execution
        if ($response instanceof ToolCallMessage) {
            return new ToolCallEvent($response, $event);
        }

        return new StopEvent();
    }
}
