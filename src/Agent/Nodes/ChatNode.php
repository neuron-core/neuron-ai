<?php

declare(strict_types=1);

namespace NeuronAI\Agent\Nodes;

use Generator;
use NeuronAI\Agent\AgentResources;
use NeuronAI\Agent\AgentState;
use NeuronAI\Agent\Events\AgentOutputEvent;
use NeuronAI\Agent\Events\AIInferenceEvent;
use NeuronAI\Agent\Events\ToolCallEvent;
use NeuronAI\Agent\InferenceRequest;
use NeuronAI\Agent\Observability\InferenceStart;
use NeuronAI\Agent\Observability\InferenceStop;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\Stream\Chunks\StreamChunk;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Exceptions\ChatHistoryException;
use NeuronAI\Providers\ProviderResponse;
use Throwable;

use function end;

/**
 * Reads the working request from AgentState after middleware has applied its changes.
 *
 * Chat and streaming are the same inference with different transport: the
 * request's stream intent selects between a buffered provider call and a live
 * chunk stream. Both paths record the same ProviderResponse under the same
 * memo, so the intent flag can never invalidate a replay.
 */
class ChatNode extends InferenceNode
{
    /**
     * @throws ChatHistoryException
     * @throws Throwable
     */
    public function __invoke(AIInferenceEvent $event, AgentState $state, AgentResources $resources): Generator|AgentOutputEvent|ToolCallEvent
    {
        $inbound = $state->request->messages;
        $messages = $this->pendingConversation($resources->history, $inbound);
        $lastMessage = end($messages);

        $this->emit(new InferenceStart($lastMessage));
        $providerResponse = $this->recallMemo('inference');

        if (!$providerResponse instanceof ProviderResponse) {
            $providerResponse = $state->request->options->stream
                ? yield from $this->stream($resources, $state->request, $messages)
                : $this->chat($resources, $state->request, $messages);

            // Only the terminal response is durable; a live stream cannot be replayed.
            $providerResponse = $this->memoize('inference', fn (): ProviderResponse => $providerResponse);
        }

        $this->emit(new InferenceStop($lastMessage, $providerResponse));

        $this->addToChatHistory($resources->history, $state, $inbound, 'history.inbound');
        $state->setResponse($providerResponse);
        $message = $providerResponse->message();

        // The tool node owns writing the tool call message to chat history.
        if ($message instanceof ToolCallMessage) {
            return new ToolCallEvent($message);
        }

        $this->addToChatHistory($resources->history, $state, $message, 'history.response');

        return new AgentOutputEvent();
    }

    /**
     * @param Message[] $messages
     * @return Generator<int, StreamChunk, mixed, ProviderResponse>
     * @throws Throwable
     */
    protected function stream(AgentResources $resources, InferenceRequest $request, array $messages): Generator
    {
        return yield from $resources->provider
            ->systemPrompt($request->instructions)
            ->setTools($resources->tools->all())
            ->stream(...$messages);
    }

    /**
     * Extracted so subclasses can customize the provider call (async
     * operations, retries, caching).
     *
     * @param Message[] $messages
     */
    protected function chat(AgentResources $resources, InferenceRequest $request, array $messages): ProviderResponse
    {
        return $resources->provider
            ->systemPrompt($request->instructions)
            ->setTools($resources->tools->all())
            ->chat(...$messages);
    }
}
