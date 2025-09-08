<?php

declare(strict_types=1);

namespace NeuronAI\Providers\OpenAI;

use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\RequestOptions;
use NeuronAI\Chat\Enums\MessageRole;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Exceptions\ProviderException;
use Psr\Http\Message\StreamInterface;

trait HandleStream
{
    /**
     * @throws ProviderException
     * @throws GuzzleException
     */
    public function stream(array|string $messages, callable $executeToolsCallback): \Generator
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

        $text = '';
        $toolCalls = [];

        while (! $stream->eof()) {
            if (!$line = $this->parseNextDataLine($stream)) {
                continue;
            }

            // Inform the agent about usage when stream
            if (!empty($line['usage'])) {
                yield \json_encode(['usage' => [
                    'input_tokens' => $line['usage']['prompt_tokens'],
                    'output_tokens' => $line['usage']['completion_tokens'],
                ]]);
            }

            if (empty($line['choices'])) {
                continue;
            }

            // Compile tool calls
            if (isset($line['choices'][0]['delta']['tool_calls'])) {
                $toolCalls = $this->composeToolCalls($line, $toolCalls);
                continue;
            }

            // Handle tool calls
            if (isset($line['choices'][0]['finish_reason']) && $line['choices'][0]['finish_reason'] === 'tool_calls') {
                yield from $executeToolsCallback(
                    $this->createToolCallMessage([
                        'content' => $text,
                        'tool_calls' => $toolCalls
                    ])
                );

                return;
            }

            // Process regular content
            $content = $line['choices'][0]['delta']['content'] ?? '';
            $text .= $content;

            yield $content;
        }
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
