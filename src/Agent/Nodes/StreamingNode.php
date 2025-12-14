<?php

declare(strict_types=1);

namespace NeuronAI\Agent\Nodes;

use NeuronAI\Agent\AgentState;
use NeuronAI\Agent\ChatHistoryHelper;
use NeuronAI\Agent\Events\AIInferenceEvent;
use NeuronAI\Agent\Events\ToolCallEvent;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Observability\EventBus;
use NeuronAI\Observability\Events\AgentError;
use NeuronAI\Observability\Events\InferenceStart;
use NeuronAI\Observability\Events\InferenceStop;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Workflow\Events\StopEvent;
use NeuronAI\Workflow\Node;
use Generator;
use Throwable;

class StreamingNode extends Node
{
    use ChatHistoryHelper;

    /**
     * @param Message[] $messages
     */
    public function __construct(
        protected AIProviderInterface $provider,
        protected Message|array $messages
    ) {
    }

    /**
     * @throws Throwable
     */
    public function __invoke(AIInferenceEvent $event, AgentState $state): Generator|ToolCallEvent
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

        try {
            $stream = $this->provider
                ->systemPrompt($event->instructions)
                ->setTools($event->tools)
                ->stream($chatHistory->getMessages());

            // Yield all chunks as-is (TextChunk, ReasoningChunk, etc.)
            foreach ($stream as $chunk) {
                yield $chunk;
            }

            // Get the final message from the generator return value
            $message = $stream->getReturn();

            EventBus::emit(
                'inference-stop',
                $this,
                new InferenceStop($lastMessage, $message)
            );

            // Add the message to the chat history
            $this->addToChatHistory($state, $message);

            // Route based on the message type
            if ($message instanceof ToolCallMessage) {
                return new ToolCallEvent($message, $event);
            }

            return new StopEvent();

        } catch (Throwable $exception) {
            EventBus::emit('error', $this, new AgentError($exception));
            throw $exception;
        }
    }
}
