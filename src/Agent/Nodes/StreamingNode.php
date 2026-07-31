<?php

declare(strict_types=1);

namespace NeuronAI\Agent\Nodes;

use NeuronAI\Agent\AgentState;
use NeuronAI\Agent\Events\AIInferenceEvent;
use NeuronAI\Agent\Events\ToolCallEvent;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Observability\Events\AgentError;
use NeuronAI\Observability\Events\InferenceStart;
use NeuronAI\Observability\Events\InferenceStop;
use NeuronAI\Providers\ProviderResponse;
use NeuronAI\Workflow\Events\StopEvent;
use Generator;
use Throwable;

class StreamingNode extends InferenceNode
{
    /**
     * @throws Throwable
     */
    public function __invoke(AIInferenceEvent $event, AgentState $state): Generator|ToolCallEvent
    {
        $this->addToChatHistory($event->getMessages(), 'history.inbound');

        $chatHistory = $this->chatHistory;
        $lastMessage = $chatHistory->getLastMessage();

        $this->emit(new InferenceStart($lastMessage));

        try {
            // A provider stream is a live, non-resumable cursor: it cannot be
            // replayed, and there is no consumer across a crash to receive chunks
            // anyway. So only the terminal response is durable. On recovery we
            // recall it and skip the stream entirely (no re-inference); on the
            // live path we stream chunks to the consumer, then record the response
            // so a crash before the node step commits won't re-bill the provider.
            $providerResponse = $this->recallMemo('inference');

            if (!$providerResponse instanceof ProviderResponse) {
                $stream = $this->provider
                    ->systemPrompt($event->instructions)
                    ->setTools($event->tools)
                    ->stream(...$chatHistory->getMessages());

                // Yield all chunks as-is (TextChunk, ReasoningChunk, etc.)
                foreach ($stream as $chunk) {
                    yield $chunk;
                }

                // Get the final message from the generator return value
                $providerResponse = $stream->getReturn();

                $this->memoize('inference', fn (): ProviderResponse => $providerResponse);
            }

            $state->setResponse($providerResponse);
            $message = $providerResponse->message();

            $this->emit(new InferenceStop($lastMessage, $providerResponse));

            // Route based on the message type
            if ($message instanceof ToolCallMessage) {
                return new ToolCallEvent($message, $event);
            }

            // Add the final message to the chat history (after tool loop)
            $this->addToChatHistory($message, 'history.response');

            return new StopEvent();

        } catch (Throwable $exception) {
            $this->emit(new AgentError($exception));
            throw $exception;
        }
    }
}
