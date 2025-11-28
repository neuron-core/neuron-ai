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
use Psr\Http\Message\StreamInterface;

trait HandleStream
{
    protected StreamState $streamState;
    /**
     * Stream response from the LLM.
     *
     * Yields intermediate chunks during streaming and returns the final complete Message.
     *
     * @throws ProviderException
     * @throws GuzzleException
     */
    public function stream(array|string $messages): \Generator
    {
        // Attach the system prompt
        if (isset($this->system)) {
            \array_unshift($messages, new Message(MessageRole::SYSTEM, $this->system));
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
        $toolCalls = [];
        $text = '';

        while (! $stream->eof()) {
            if (!$line = SSEParser::parseNextSSEEvent($stream)) {
                continue;
            }

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
                // accumulate tool calls via stream state
                $this->streamState->composeToolCalls($line);
                $toolCalls = $this->streamState->getToolCalls();

                if ($this->finishForToolCall($choice)) {
                    goto finish;
                }

                continue;
            }

            // Handle tool calls
            if ($this->finishForToolCall($choice)) {
                finish:
                // Create ToolCallMessage with accumulated content
                $message = $this->createToolCallMessage([
                    'content' => $text,
                    'tool_calls' => $toolCalls
                ]);
                $message->setUsage($this->streamState->getUsage());

                return $message;
            }

            // Process regular content
            $content = $choice['delta']['content'] ?? '';
            $text .= $content;

            if ($content !== '') {
                yield new TextChunk($this->streamState->messageId(), $content);
            }
        }

        // Build final message
        $blocks = [];
        if ($text !== '') {
            $blocks[] = new TextContent($text);
        }

        $message = new AssistantMessage($blocks);
        $message->setUsage($this->streamState->getUsage());

        return $message;
    }

    protected function finishForToolCall(array $choice): bool
    {
        return isset($choice['finish_reason']) && $choice['finish_reason'] === 'tool_calls';
    }

    /*protected function parseNextDataLine(StreamInterface $stream): ?array
    {
        $line = $this->readLine($stream);

        if (! \str_starts_with((string) $line, 'data:')) {
            return null;
        }

        $line = \trim(\substr((string) $line, \strlen('data: ')));

        if (\str_contains($line, 'DONE')) {
            return null;
        }

        try {
            return \json_decode($line, true, flags: \JSON_THROW_ON_ERROR);
        } catch (\Throwable $exception) {
            throw new ProviderException('OpenAI streaming error - '.$exception->getMessage());
        }
    }*/
}
