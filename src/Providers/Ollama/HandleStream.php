<?php

declare(strict_types=1);

namespace NeuronAI\Providers\Ollama;

use NeuronAI\Chat\Enums\MessageRole;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ContentBlocks\TextContent;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\Stream\Events\TextChunk;
use NeuronAI\Chat\Messages\Usage;
use Psr\Http\Message\StreamInterface;

trait HandleStream
{
    /**
     * Stream response from the LLM.
     *
     * Yields intermediate chunks during streaming and returns the final complete Message.
     */
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
        $usage = new Usage(0, 0);

        while (! $stream->eof()) {
            if (!$line = $this->parseNextJson($stream)) {
                continue;
            }

            // Last chunk will contain the usage information
            if ($line['done'] === true) {
                $usage->inputTokens += $line['prompt_eval_count'] ?? 0;
                $usage->outputTokens += $line['eval_count'] ?? 0;
                continue;
            }

            // Process tool calls
            if (isset($line['message']['tool_calls'])) {
                // Preserve any accumulated text content before tool call
                $messageData = $line['message'];
                if ($text !== '') {
                    $messageData['content'] = $text;
                }

                $message = $this->createToolCallMessage($messageData);
                $message->setUsage($usage);

                return $message;
            }

            // Process regular content
            $content = $line['message']['content'] ?? '';
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
