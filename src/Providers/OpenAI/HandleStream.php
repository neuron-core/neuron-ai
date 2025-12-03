<?php

declare(strict_types=1);

namespace NeuronAI\Providers\OpenAI;

use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\RequestOptions;
use NeuronAI\Chat\Enums\MessageRole;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ContentBlocks\TextContent;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronAI\Chat\Messages\Usage;
use NeuronAI\Exceptions\ProviderException;
use NeuronAI\Providers\SSEParser;
use Generator;

use Psr\Http\Message\StreamInterface;
use function array_unshift;

trait HandleStream
{
    protected StreamState $streamState;

    /**
     * Stream response from the LLM.
     * https://platform.openai.com/docs/api-reference/chat-streaming
     *
     * @throws ProviderException
     * @throws GuzzleException
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

        $stream = $this->client->post('chat/completions', [
            'stream' => true,
            RequestOptions::JSON => $json
        ])->getBody();

        $this->streamState = new StreamState();

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
                $this->processToolCallDelta($choice);

                if ($this->finishForToolCall($choice)) {
                    goto toolcall;
                }

                continue;
            }

            // Handle tool calls
            if ($this->finishForToolCall($choice)) {
                toolcall:
                $this->processToolCallDelta($choice);
                $message = $this->createToolCallMessage(
                    $this->streamState->getToolCalls(),
                    $this->streamState->getContentBlocks()
                );
                $message->setUsage($this->streamState->getUsage());
                $this->enrichMessage($message);

                return $message;
            }

            // Process regular content
            if ($content = $choice['delta']['content'] ?? null) {
                $this->streamState->updateContentBlock($choice['index'], new TextContent($content));
                yield new TextChunk($this->streamState->messageId(), $content);
            }

            // Process provider-specific delta content
            $this->processContentDelta($choice);
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
     */
    protected function processToolCallDelta(array $choice): void
    {
        // ...
    }

    /**
     * Streaming Hook. Override in child classes to handle provider-specific fields.
     * Called when processing content deltas. Use streamState->accumulateMetadata()
     * to store provider-specific data that will be available in enrichMessage().
     */
    protected function processContentDelta(array $choice): void
    {
        // ...
    }
}
