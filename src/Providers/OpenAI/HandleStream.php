<?php

declare(strict_types=1);

namespace NeuronAI\Providers\OpenAI;

use Generator;
use NeuronAI\Chat\Enums\MessageRole;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ContentBlocks\TextContent;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\Stream\Chunks\StreamChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\ToolArgumentChunk;
use NeuronAI\Exceptions\HttpException;
use NeuronAI\Exceptions\ProviderException;
use NeuronAI\HttpClient\StreamInterface;
use NeuronAI\Providers\HandleEarlyStreamEnd;
use NeuronAI\Providers\ProviderResponse;
use NeuronAI\Providers\SSEParser;

use function array_unshift;
use function json_encode;

trait HandleStream
{
    use HandleEarlyStreamEnd;

    protected StreamState $streamState;

    /**
     * Stream response from the LLM.
     * https://platform.openai.com/docs/api-reference/chat-streaming
     *
     * @throws ProviderException
     * @throws HttpException
     */
    public function stream(Message ...$messages): Generator
    {
        // Attach the system prompt
        if (isset($this->system)) {
            array_unshift($messages, new Message(MessageRole::SYSTEM, $this->system));
        }

        $body = [
            'stream' => true,
            'model' => $this->model,
            'messages' => $this->messageMapper()->map($messages),
            'stream_options' => ['include_usage' => true],
            ...$this->parameters,
        ];

        // Attach tools
        if (!empty($this->tools)) {
            $body['tools'] = $this->toolPayloadMapper()->map($this->tools);
        }

        $stream = $this->httpClient->stream(
            $this->createChatHttpRequest($body)
        );

        $this->streamState = new StreamState();

        return yield from $this->processStream($stream);
    }

    /**
     * @throws ProviderException
     */
    protected function processStream(StreamInterface $stream): Generator
    {
        while (! $stream->eof()) {
            if (!$line = SSEParser::parseNextSSEEvent($stream)) {
                continue;
            }

            // Shared by every OpenAI-compatible provider, so the message names none
            if (isset($line['error'])) {
                throw new ProviderException('Streaming error: '.($line['error']['message'] ?? json_encode($line['error'])));
            }

            // Capture usage information
            if (!empty($line['usage'])) {
                $this->streamState->addInputTokens($line['usage']['prompt_tokens'] ?? 0);
                $this->streamState->addOutputTokens($line['usage']['completion_tokens'] ?? 0);
                $this->streamState->addCachedInputTokens($line['usage']['prompt_tokens_details']['cached_tokens'] ?? 0);
                $this->streamState->addReasoningTokens($line['usage']['completion_tokens_details']['reasoning_tokens'] ?? 0);
            }

            if (empty($line['choices'])) {
                continue;
            }

            $choice = $line['choices'][0];

            if (isset($choice['finish_reason'])) {
                $this->streamState->setStopReason($choice['finish_reason']);
            }

            // Compile tool calls
            if (isset($choice['delta']['tool_calls'])) {
                $this->streamState->composeToolCalls($line);
                yield from $this->processToolCallDelta($choice);
                continue;
            }

            // The finish of a turn that collected tool calls
            if ($this->finishForToolCall($choice)) {
                yield from $this->processToolCallDelta($choice);
                continue;
            }

            // Process provider-specific delta content and yield custom chunks
            yield from $this->processContentDelta($choice);
        }

        // The last choice carries the finish reason: without it the stream ended early
        if ($this->streamState->stopReason() === null) {
            return $this->earlyEndResponse($stream, $this->streamState->getContentBlocks(), $this->streamState->messageId(), $this->streamState->getUsage());
        }

        // The usage follows the finish reason in a frame of its own, so the message
        // is built once the stream has been read to its end, tool calls included.
        $message = $this->streamState->hasToolCalls()
            ? $this->createToolCallMessage($this->streamState->getToolCalls(), $this->streamState->getContentBlocks())
            : new AssistantMessage($this->streamState->getContentBlocks());
        $message->setId($this->streamState->messageId())->setUsage($this->streamState->getUsage());
        $this->applyStreamMetadata($message);
        $this->enrichMessage($message);

        return new ProviderResponse(message: $message);
    }

    /**
     * What this stream collected, its stop reason and the metadata its hooks accumulated,
     * belongs to this stream's message only: applying it here, never in enrichMessage(),
     * keeps it out of a later chat() answer.
     */
    protected function applyStreamMetadata(AssistantMessage $message): void
    {
        if ($this->streamState->stopReason() !== null) {
            $message->setStopReason($this->streamState->stopReason());
        }

        foreach ($this->streamState->getMetadata() as $key => $value) {
            if ($message->getMetadata($key) === null) {
                $message->addMetadata($key, $value);
            }
        }
    }

    /**
     * Any finish reason ends a turn that collected tool calls: a forced
     * tool_choice answers "stop", not "tool_calls".
     */
    protected function finishForToolCall(array $choice): bool
    {
        return isset($choice['finish_reason']) && $this->streamState->hasToolCalls();
    }

    /**
     * Streaming Hook. Override in child classes to handle provider-specific fields.
     * Called when processing tool call deltas. Use streamState->accumulateMetadata()
     * to store provider-specific data that will be available in enrichMessage().
     *
     * Can yield custom chunk types (e.g., ReasoningChunk) for real-time streaming.
     * Overrides should call `yield from parent::processToolCallDelta($choice)` to
     * preserve tool argument streaming.
     *
     * @return Generator<StreamChunk>
     */
    protected function processToolCallDelta(array $choice): Generator
    {
        foreach ($choice['delta']['tool_calls'] ?? [] as $call) {
            $arguments = $call['function']['arguments'] ?? '';
            if ($arguments === '') {
                continue;
            }

            // The entry is created by composeToolCalls() on the delta carrying the tool name.
            $toolCall = $this->streamState->getToolCall($call['index']);
            if ($toolCall === null) {
                continue;
            }

            yield new ToolArgumentChunk(
                $this->streamState->messageId(),
                $toolCall['function']['name'],
                $arguments,
                $toolCall['id'] ?? null,
            );
        }
    }

    /**
     * Streaming Hook. Override in child classes to handle provider-specific fields.
     * Called when processing content deltas. Use streamState->accumulateMetadata()
     * to store provider-specific data that will be available in enrichMessage().
     *
     * Can yield custom chunk types (e.g., ReasoningChunk) for real-time streaming.
     *
     * @return Generator<StreamChunk>
     */
    protected function processContentDelta(array $choice): Generator
    {
        $content = $choice['delta']['content'] ?? null;
        if ($content !== null) {
            $this->streamState->updateContentBlock($choice['index'] ?? 0, new TextContent($content));
            yield new TextChunk($this->streamState->messageId(), $content);
        }
    }
}
