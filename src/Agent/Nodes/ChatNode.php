<?php

declare(strict_types=1);

namespace NeuronAI\Agent\Nodes;

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

        // The tool node owns writing the tool call message to chat history.
        if ($message instanceof ToolCallMessage) {
            return new ToolCallEvent($message, $event);
        }

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

            // A provider stream is a live, non-resumable cursor, so only the
            // terminal response is durable: on recovery it is recalled and the
            // stream skipped entirely; on the live path it is recorded after
            // streaming so a crash before the step commits won't re-bill.
            $providerResponse = $this->recallMemo('inference');

            if (!$providerResponse instanceof ProviderResponse) {
                $stream = $this->provider
                    ->systemPrompt($event->instructions)
                    ->setTools($event->tools)
                    ->stream(...$messages);

                foreach ($stream as $chunk) {
                    yield $chunk;
                }

                $providerResponse = $stream->getReturn();

                $this->memoize('inference', fn (): ProviderResponse => $providerResponse);
            }

            $this->emit(new InferenceStop($lastMessage, $providerResponse));

            $this->addToChatHistory($inbound, 'history.inbound');

            $state->setResponse($providerResponse);
            $message = $providerResponse->message();

            if ($message instanceof ToolCallMessage) {
                return new ToolCallEvent($message, $event);
            }

            $this->addToChatHistory($message, 'history.response');

            return new StopEvent();

        } catch (Throwable $exception) {
            $this->emit(new AgentError($exception));
            throw $exception;
        }
    }

    /**
     * Extracted so subclasses can customize the provider call (async
     * operations, retries, caching).
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
