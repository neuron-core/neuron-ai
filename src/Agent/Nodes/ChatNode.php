<?php

declare(strict_types=1);

namespace NeuronAI\Agent\Nodes;

use Inspector\Exceptions\InspectorException;
use NeuronAI\Agent\AgentState;
use NeuronAI\Agent\Events\AIInferenceEvent;
use NeuronAI\Agent\Events\ToolCallEvent;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Exceptions\ChatHistoryException;
use NeuronAI\Observability\Events\AgentError;
use NeuronAI\Observability\Events\InferenceStart;
use NeuronAI\Observability\Events\InferenceStop;
use NeuronAI\Providers\ProviderResponse;
use NeuronAI\Workflow\Events\StopEvent;
use Generator;
use Throwable;

use function end;

/**
 * Receives an AIInferenceEvent containing instructions and tools that middleware can
 * modify before the actual inference call is made.
 *
 * Chat and streaming are the same inference with different transport: the
 * event's stream intent selects between a buffered provider call and a live
 * chunk stream. Both paths record the same ProviderResponse under the same
 * memo, so the intent flag can never invalidate a replay.
 */
class ChatNode extends InferenceNode
{
    /**
     * @throws ChatHistoryException
     * @throws Throwable
     */
    public function __invoke(AIInferenceEvent $event, AgentState $state): Generator|StopEvent|ToolCallEvent
    {
        if ($event->stream) {
            return $this->streamedInference($event, $state);
        }

        $inbound = $event->getMessages();
        $messages = $this->pendingConversation($inbound);
        $lastMessage = end($messages);

        $this->emit(new InferenceStart($lastMessage));
        $providerResponse = $this->memoize(
            'inference',
            fn (): ProviderResponse => $this->inference($event, $messages),
        );
        $this->emit(new InferenceStop($lastMessage, $providerResponse));

        $this->addToChatHistory($inbound, 'history.inbound');
        $state->setResponse($providerResponse);
        $message = $providerResponse->message();

        // If the response is a tool call, route to the tool node.
        // It will be responsible to add the tool call message to the chat history.
        if ($message instanceof ToolCallMessage) {
            return new ToolCallEvent($message, $event);
        }

        // Add the final response to chat history (after tool loop)
        $this->addToChatHistory($message, 'history.response');

        return new StopEvent();
    }

    /**
     * The streaming transport, kept in its own generator method: a function
     * body containing yield is always a Generator function, so the buffered
     * chat path above must live outside it to return events directly.
     *
     * @throws ChatHistoryException
     * @throws Throwable
     */
    protected function streamedInference(AIInferenceEvent $event, AgentState $state): Generator
    {
        $inbound = $event->getMessages();
        $messages = $this->pendingConversation($inbound);
        $lastMessage = end($messages);

        try {
            $this->emit(new InferenceStart($lastMessage));

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
                    ->stream(...$messages);

                // Yield all chunks as-is (TextChunk, ReasoningChunk, etc.)
                foreach ($stream as $chunk) {
                    yield $chunk;
                }

                // Get the final message from the generator return value
                $providerResponse = $stream->getReturn();

                $this->memoize('inference', fn (): ProviderResponse => $providerResponse);
            }

            $this->emit(new InferenceStop($lastMessage, $providerResponse));

            $this->addToChatHistory($inbound, 'history.inbound');

            $state->setResponse($providerResponse);
            $message = $providerResponse->message();

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
    protected function inference(AIInferenceEvent $event, array $messages): ProviderResponse
    {
        return $this->provider
            ->systemPrompt($event->instructions)
            ->setTools($event->tools)
            ->chat(...$messages);
    }
}
