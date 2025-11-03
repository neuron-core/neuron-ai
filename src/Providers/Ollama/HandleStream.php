<?php

declare(strict_types=1);

namespace NeuronAI\Providers\Ollama;

use NeuronAI\Chat\Enums\MessageRole;
use NeuronAI\Chat\Messages\Message;
use Psr\Http\Message\StreamInterface;

trait HandleStream
{
    public function stream(array|string $messages): \Generator
    {
        // Include the system prompt
        if (isset($this->system)) {
            \array_unshift($messages, new Message(MessageRole::SYSTEM, $this->system));
        }

        $json = [
            'stream' => true,
            'model' => $this->model,
            'messages' => $this->messageMapper()->map($messages),
            ...$this->parameters,
        ];

        if (!empty($this->tools)) {
            $json['tools'] = $this->toolPayloadMapper()->map($this->tools);
        }

        $stream = $this->client->post('chat', [
            'stream' => true,
            ...['json' => $json]
        ])->getBody();

        $text = '';

        while (! $stream->eof()) {
            if (!$line = $this->parseNextJson($stream)) {
                continue;
            }

            // Last chunk will contain the usage information.
            if ($line['done'] === true) {
                yield \json_encode(['usage' => [
                    'input_tokens' => $line['prompt_eval_count'],
                    'output_tokens' => $line['eval_count'],
                ]]);
                continue;
            }

            // Process tool calls
            if (isset($line['message']['tool_calls'])) {
                // Preserve any accumulated text content before tool call
                $message = $line['message'];
                if ($text !== '') {
                    $message['content'] = $text;
                }

                yield $this->createToolCallMessage($message);
                return;
            }

            // Process regular content
            $content = $line['message']['content'] ?? '';
            $text .= $content;

            yield $content;
        }
    }

    protected function parseNextJson(StreamInterface $stream): ?array
    {
        $line = $this->readLine($stream);

        if (empty($line)) {
            return null;
        }

        $json = \json_decode((string) $line, true);

        if ($json['done']) {
            return null;
        }

        if (! isset($json['message']) || $json['message']['role'] !== 'assistant') {
            return null;
        }

        return $json;
    }

    protected function readLine(StreamInterface $stream): string
    {
        $buffer = '';

        while (! $stream->eof()) {
            if ('' === ($byte = $stream->read(1))) {
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
