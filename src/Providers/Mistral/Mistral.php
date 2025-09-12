<?php

declare(strict_types=1);

namespace NeuronAI\Providers\Mistral;

use GuzzleHttp\Exception\GuzzleException;
use NeuronAI\Chat\Enums\MessageRole;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Exceptions\ProviderException;
use NeuronAI\Providers\OpenAI\OpenAI;

class Mistral extends OpenAI
{
    protected string $baseUri = 'https://api.mistral.ai/v1';

    /**
     * @throws ProviderException
     * @throws GuzzleException
     */
    public function stream(array|string $messages, callable $executeToolsCallback): \Generator
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

        while (! $stream->eof()) {
            if (($line = $this->parseNextDataLine($stream)) === null) {
                continue;
            }

            // Inform the agent about usage when stream
            if (empty($line['choices']) && !empty($line['usage'])) {
                yield \json_encode(['usage' => [
                    'input_tokens' => $line['usage']['prompt_tokens'],
                    'output_tokens' => $line['usage']['completion_tokens'],
                ]]);
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
                    yield from $executeToolsCallback(
                        $this->createToolCallMessage([
                            'content' => $text,
                            'tool_calls' => $toolCalls
                        ])
                    );
                }

                continue;
            }

            // Process regular content
            $content = $line['choices'][0]['delta']['content'] ?? '';
            $text .= $content;

            yield $content;
        }
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
