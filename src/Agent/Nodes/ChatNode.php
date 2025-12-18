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
 * Receives an AIInferenceEvent containing instructions and tools that middleware can
 * modify before the actual inference call is made.
 */
class ChatNode extends Node
{
    use ChatHistoryHelper;

    public function __construct(
        protected AIProviderInterface $provider,
    ) {
    }

    /**
     * @throws InspectorException
     */
    public function __invoke(AIInferenceEvent $event, AgentState $state): StopEvent|ToolCallEvent
    {
        $this->addToChatHistory($state, $event->getMessages());

        $chatHistory = $state->getChatHistory();
        $lastMessage = $chatHistory->getLastMessage();

        EventBus::emit(
            'inference-start',
            $this,
            new InferenceStart($lastMessage)
        );

        $response = $this->inference($event, $chatHistory->getMessages());

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

    /**
     * Perform the actual inference call to the AI provider.
     *
     * This method is extracted to allow easy customization of the inference behavior.
     * Subclasses can override this method to:
     * - Use async operations (chatAsync with Amp, ReactPHP, etc.)
     * - Add custom retry logic
     * - Implement caching
     * - Add custom error handling
     *
     * @param Message[] $messages
     */
    protected function inference(AIInferenceEvent $event, array $messages): Message
    {
        return $this->provider
            ->systemPrompt($event->instructions)
            ->setTools($event->tools)
            ->chat($messages);
    }
}
