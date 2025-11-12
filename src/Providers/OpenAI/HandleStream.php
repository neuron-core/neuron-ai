<?php

declare(strict_types=1);

namespace NeuronAI\Providers\OpenAI;

use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\RequestOptions;
use NeuronAI\Chat\Enums\MessageRole;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ContentBlocks\TextContent;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\Stream\TextChunk;
use NeuronAI\Chat\Messages\Usage;
use NeuronAI\Exceptions\ProviderException;
use Psr\Http\Message\StreamInterface;

trait HandleStream
{
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

        $toolCalls = [];
        $text = '';
        $usage = new Usage(0, 0);

        while (! $stream->eof()) {
            if (!$line = $this->parseNextDataLine($stream)) {
                continue;
            }

            // Capture usage information
            if (!empty($line['usage'])) {
                $usage->inputTokens += $line['usage']['prompt_tokens'] ?? 0;
                $usage->outputTokens += $line['usage']['completion_tokens'] ?? 0;
            }

            if (empty($line['choices'])) {
                continue;
            }

            $choice = $line['choices'][0];

            // Compile tool calls
            if (isset($choice['delta']['tool_calls'])) {
                $toolCalls = $this->composeToolCalls($line, $toolCalls);

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
                $message->setUsage($usage);

                return $message;
            }

            // Process regular content
            $content = $choice['delta']['content'] ?? '';
            $text .= $content;

            if ($content !== '') {
                yield new TextChunk($content);
            }
        }

        // Build final message
        $blocks = [];
        if ($text !== '') {
            $blocks[] = new TextContent($text);
        }

        $message = new AssistantMessage($blocks);
        $message->setUsage($usage);

        return $message;
    }

    protected function finishForToolCall(array $choice): bool
    {
        return isset($choice['finish_reason']) && $choice['finish_reason'] === 'tool_calls';
    }

    /**
     * Recreate the tool_calls format from streaming OpenAI API.
     *
     * @param  array<string, mixed>  $line
     * @param  array<int, array<string, mixed>>  $toolCalls
     * @return array<int, array<string, mixed>>
     */
    protected function composeToolCalls(array $line, array $toolCalls): array
    {
        foreach ($line['choices'][0]['delta']['tool_calls'] as $call) {
            $index = $call['index'];

            if (!\array_key_exists($index, $toolCalls)) {
                if ($name = $call['function']['name'] ?? null) {
                    $toolCalls[$index]['function'] = ['name' => $name, 'arguments' => $call['function']['arguments'] ?? ''];
                    $toolCalls[$index]['id'] = $call['id'];
                    $toolCalls[$index]['type'] = 'function';
                }
            } else {
                $arguments = $call['function']['arguments'] ?? null;
                if ($arguments !== null) {
                    $toolCalls[$index]['function']['arguments'] .= $arguments;
                }
            }
        }

        return $toolCalls;
    }

    protected function parseNextDataLine(StreamInterface $stream): ?array
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
    }

    protected function readLine(StreamInterface $stream): string
    {
        $buffer = '';

        while (! $stream->eof()) {
            $byte = $stream->read(1);

            if ($byte === '') {
                return $buffer;
            }

            $buffer .= $byte;

            if ($byte === "\n") {
                break;
            }
        }

        return $buffer;
    }
}
