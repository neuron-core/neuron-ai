<?php

declare(strict_types=1);

namespace NeuronAI\Providers\Gemini;

use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\RequestOptions;
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
        $json = [
            'contents' => $this->messageMapper()->map($messages),
            ...$this->parameters
        ];

        if (isset($this->system)) {
            $json['system_instruction'] = [
                'parts' => [
                    ['text' => $this->system]
                ]
            ];
        }

        if (!empty($this->tools)) {
            $json['tools'] = $this->toolPayloadMapper()->map($this->tools);
        }

        $stream = $this->client->post(\trim($this->baseUri, '/')."/{$this->model}:streamGenerateContent", [
            'stream' => true,
            RequestOptions::JSON => $json
        ])->getBody();

        $text = '';
        $toolCalls = [];

        while (! $stream->eof()) {
            $line = $this->readLine($stream);

            if (($line = \json_decode((string) $line, true)) === null) {
                continue;
            }

            // Inform the agent about usage when stream
            if (\array_key_exists('usageMetadata', $line)) {
                yield \json_encode(['usage' => [
                    'input_tokens' => $line['usageMetadata']['promptTokenCount'],
                    'output_tokens' => $line['usageMetadata']['candidatesTokenCount'] ?? 0,
                ]]);
            }

            // Process tool calls
            if ($this->hasToolCalls($line)) {
                $toolCalls = $this->composeToolCalls($line, $toolCalls);

                // Handle tool calls
                if (isset($line['candidates'][0]['finishReason']) && $line['candidates'][0]['finishReason'] === 'STOP') {
                    yield from $executeToolsCallback(
                        $this->createToolCallMessage([
                            'content' => $text,
                            'parts' => $toolCalls
                        ])
                    );

                    return;
                }

                continue;
            }

            // Process regular content
            $content = $line['candidates'][0]['content']['parts'][0]['text'] ?? '';
            $text .= $content;

            yield $content;
        }
    }

    /**
     * Recreate the tool_calls format from streaming Gemini API.
     */
    protected function composeToolCalls(array $line, array $toolCalls): array
    {
        $parts = $line['candidates'][0]['content']['parts'] ?? [];

        foreach ($parts as $index => $part) {
            if (isset($part['functionCall'])) {
                $toolCalls[$index]['functionCall'] = $part['functionCall'];
            }
        }

        return $toolCalls;
    }

    /**
     * Determines if the given line contains tool function calls.
     *
     * @param array $line The data line to check for tool function calls.
     * @return bool Returns true if the line contains tool function calls, otherwise false.
     */
    protected function hasToolCalls(array $line): bool
    {
        $parts = $line['candidates'][0]['content']['parts'] ?? [];

        foreach ($parts as $part) {
            if (isset($part['functionCall'])) {
                return true;
            }
        }

        return false;
    }

    private function readLine(StreamInterface $stream): string
    {
        $buffer = '';

        while (! $stream->eof()) {
            $buffer .= $stream->read(1);

            if (\strlen($buffer) === 1 && $buffer !== '{') {
                $buffer = '';
            }

            if (\json_decode($buffer) !== null) {
                return $buffer;
            }
        }

        return \rtrim($buffer, ']');
    }
}
