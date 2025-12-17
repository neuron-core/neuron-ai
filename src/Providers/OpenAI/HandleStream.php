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
use NeuronAI\Exceptions\ProviderException;
use NeuronAI\Exceptions\HttpException;
use NeuronAI\HttpClient\StreamInterface;
use NeuronAI\Providers\SSEParser;

use function array_unshift;

trait HandleStream
{
    protected StreamState $streamState;

    /**
     * Stream response from the LLM.
     * https://platform.openai.com/docs/api-reference/chat-streaming
     *
     * @throws ProviderException
     * @throws HttpException
     */
    public function stream(array|string $messages): Generator
    {
        // Attach the system prompt
        if (isset($this->system)) {
            array_unshift($messages, new Message(MessageRole::SYSTEM, $this->system));
        }

        $json = [
            'stream' => true,
            'model' => $this->model,
            'messages' => $this->messageMapper()->map($messages),
            'stream_options' => ['include_usage' => true],
            ...$this->parameters,
        ];

        // Attach tools
        if (!empty($this->tools)) {
            $json['tools'] = $this->toolPayloadMapper()->map($this->tools);
        }

        $stream = $this->httpClient->stream(
            $this->createChatHttpRequest($json)
        );

        $this->streamState = new StreamState();

        yield from $this->processStream($stream);
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

            $this->streamState->messageId($line['id']);

            // Capture usage information
            if (!empty($line['usage'])) {
                $this->streamState->addInputTokens($line['usage']['prompt_tokens'] ?? 0);
                $this->streamState->addOutputTokens($line['usage']['completion_tokens'] ?? 0);
            }

            if (empty($line['choices'])) {
                continue;
            }

            $choice = $line['choices'][0];

            // Compile tool calls
            if (isset($choice['delta']['tool_calls'])) {
                $this->streamState->composeToolCalls($line);
                yield from $this->processToolCallDelta($choice);

                if ($this->finishForToolCall($choice)) {
                    goto toolcall;
                }

                continue;
            }

            // Handle tool calls
            if ($this->finishForToolCall($choice)) {
                toolcall:
                yield from $this->processToolCallDelta($choice);
                $message = $this->createToolCallMessage(
                    $this->streamState->getToolCalls(),
                    $this->streamState->getContentBlocks()
                );
                $message->setUsage($this->streamState->getUsage());
                $this->enrichMessage($message);

                return $message;
            }

            // Process provider-specific delta content and yield custom chunks
            yield from $this->processContentDelta($choice);
        }

        // "enrichMessage" applies streamState metadata
        $message = new AssistantMessage($this->streamState->getContentBlocks());
        $message->setUsage($this->streamState->getUsage());
        $this->enrichMessage($message);

        return $message;
    }

    protected function finishForToolCall(array $choice): bool
    {
        return isset($choice['finish_reason']) && $choice['finish_reason'] === 'tool_calls';
    }

    /**
     * Streaming Hook. Override in child classes to handle provider-specific fields.
     * Called when processing tool call deltas. Use streamState->accumulateMetadata()
     * to store provider-specific data that will be available in enrichMessage().
     *
     * Can yield custom chunk types (e.g., ReasoningChunk) for real-time streaming.
     *
     * @return Generator<StreamChunk>
     */
    protected function processToolCallDelta(array $choice): Generator
    {
        yield;
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
        if ($content = $choice['delta']['content'] ?? null) {
            $this->streamState->updateContentBlock($choice['index'], new TextContent($content));
            yield new TextChunk($this->streamState->messageId(), $content);
        }
    }
}
