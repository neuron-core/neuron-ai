<?php

declare(strict_types=1);

namespace NeuronAI\Agent\Nodes;

use NeuronAI\Agent\AgentState;
use NeuronAI\Agent\ChatHistoryHelper;
use NeuronAI\Agent\Events\AIInferenceEvent;
use NeuronAI\Agent\Events\ToolCallEvent;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\ToolCallMessage;
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
        $this->addToChatHistory($state, $event->getMessages());

        $chatHistory = $state->getChatHistory();
        $lastMessage = $chatHistory->getLastMessage();

        $this->emit('inference-start', new InferenceStart($lastMessage));

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

            $this->emit('inference-stop', new InferenceStop($lastMessage, $message));

            // Add the message to the chat history
            $this->addToChatHistory($state, $message);

            // Route based on the message type
            if ($message instanceof ToolCallMessage) {
                return new ToolCallEvent($message, $event);
            }

            return new StopEvent();

        } catch (Throwable $exception) {
            $this->emit('error', new AgentError($exception));
            throw $exception;
        }
    }
}
