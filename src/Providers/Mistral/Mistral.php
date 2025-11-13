<?php

declare(strict_types=1);

namespace NeuronAI\Providers\Mistral;

use GuzzleHttp\Exception\GuzzleException;
use NeuronAI\Chat\Enums\MessageRole;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ContentBlocks\TextContent;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\Stream\Events\TextChunk;
use NeuronAI\Chat\Messages\Usage;
use NeuronAI\Exceptions\ProviderException;
use NeuronAI\Providers\OpenAI\OpenAI;

class Mistral extends OpenAI
{
    protected string $baseUri = 'https://api.mistral.ai/v1';

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
        if ($this->system !== null) {
            \array_unshift($messages, new Message(MessageRole::SYSTEM, $this->system));
        }

        $json = [
            'stream' => true,
            'model' => $this->model,
            'messages' => $this->messageMapper()->map($messages),
            ...$this->parameters,
        ];

        // Attach tools
        if ($this->tools !== []) {
            $json['tools'] = $this->toolPayloadMapper()->map($this->tools);
        }

        $stream = $this->client->post('chat/completions', [
            'stream' => true,
            ...['json' => $json]
        ])->getBody();

        $text = '';
        $toolCalls = [];
        $usage = new Usage(0, 0);

        while (! $stream->eof()) {
            if (($line = $this->parseNextDataLine($stream)) === null) {
                continue;
            }

            // Capture usage information
            if (empty($line['choices']) && !empty($line['usage'])) {
                $usage->inputTokens += $line['usage']['prompt_tokens'] ?? 0;
                $usage->outputTokens += $line['usage']['completion_tokens'] ?? 0;
                continue;
            }

            if (empty($line['choices'])) {
                continue;
            }

            // Compile tool calls
            if ($this->isToolCallPart($line)) {
                $toolCalls = $this->composeToolCalls($line, $toolCalls);

                // Handle tool calls
                if ($line['choices'][0]['finish_reason'] === 'tool_calls') {
                    $message = $this->createToolCallMessage([
                        'content' => $text,
                        'tool_calls' => $toolCalls
                    ]);
                    $message->setUsage($usage);

                    return $message;
                }

                continue;
            }

            // Process regular content
            $content = $line['choices'][0]['delta']['content'] ?? '';
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

    protected function isToolCallPart(array $line): bool
    {
        $calls = $line['choices'][0]['delta']['tool_calls'] ?? [];

        foreach ($calls as $call) {
            if (isset($call['function'])) {
                return true;
            }
        }

        return false;
    }
}
