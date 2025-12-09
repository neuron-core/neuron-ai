<?php

declare(strict_types=1);

namespace NeuronAI\Agent\Nodes;

use NeuronAI\Agent\AgentState;
use NeuronAI\Agent\ChatHistoryHelper;
use NeuronAI\Agent\Events\AIInferenceEvent;
use NeuronAI\Agent\Events\ToolCallEvent;
use NeuronAI\Chat\Messages\Stream\Chunks\ToolCallChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\ToolResultChunk;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
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

    public function __construct(
        protected AIProviderInterface $provider,
    ) {
    }

    /**
     * @throws Throwable
     */
    public function __invoke(AIInferenceEvent $event, AgentState $state): Generator|ToolCallEvent
    {
        $chatHistory = $state->getChatHistory();
        $lastMessage = $chatHistory->getLastMessage();

        EventBus::emit(
            'inference-start',
            $this,
            new InferenceStart($lastMessage)
        );

        if ($lastMessage instanceof ToolResultMessage) {
            foreach ($lastMessage->getTools() as $tool) {
                yield new ToolResultChunk($tool);
            }
        }

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
                foreach ($message->getTools() as $tool) {
                    yield new ToolCallChunk($tool);
                }
                return new ToolCallEvent($message, $event);
            }

            return new StopEvent();

        } catch (Throwable $exception) {
            EventBus::emit('error', $this, new AgentError($exception));
            throw $exception;
        }
    }
}
