<?php

declare(strict_types=1);

namespace NeuronAI\Providers\Gemini;

use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\RequestOptions;
use NeuronAI\Chat\Enums\SourceType;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ContentBlocks\ContentBlock;
use NeuronAI\Chat\Messages\ContentBlocks\ImageContent;
use NeuronAI\Chat\Messages\ContentBlocks\ReasoningContent;
use NeuronAI\Chat\Messages\ContentBlocks\TextContent;
use NeuronAI\Chat\Messages\Stream\ReasoningChunk;
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

        $toolCalls = [];
        $usage = new Usage(0, 0);

        /** @var array<string, TextContent|ReasoningContent> $blocks */
        $blocks = [
            'text' => new TextContent(''),
            'reasoning' => new ReasoningContent('')
        ];

        while (! $stream->eof()) {
            $line = $this->readLine($stream);

            if (($line = \json_decode((string) $line, true)) === null) {
                continue;
            }

            // Capture usage information
            if (\array_key_exists('usageMetadata', $line)) {
                $usage->inputTokens += $line['usageMetadata']['promptTokenCount'] ?? 0;
                $usage->outputTokens += $line['usageMetadata']['candidatesTokenCount'] ?? 0;
            }

            // Process tool calls
            if ($this->hasToolCalls($line)) {
                $toolCalls = $this->composeToolCalls($line, $toolCalls);

                // Handle tool calls when finished
                if (isset($line['candidates'][0]['finishReason']) && $line['candidates'][0]['finishReason'] === 'STOP') {
                    return $this->createToolCallMessage(\array_values($blocks), $toolCalls)->setUsage($usage);
                }

                continue;
            }

            // Process content
            $part = $line['candidates'][0]['content']['parts'][0] ?? null;

            if ($part === null) {
                continue;
            }

            if (isset($part['text'])) {
                if ($part['thought']) {
                    // Accumulate the reasoning text
                    $blocks['reasoning']->text .= $part['text'];
                    yield new ReasoningChunk($part['text']);
                } else {
                    // Accumulate simple text output
                    $blocks['text']->text .= $part['text'];
                    yield new TextChunk($part['text']);
                }
                continue;
            }

            if (isset($part['inlineData'])) {
                $blocks['image'] = new ImageContent(
                    $part['inlineData']['data'],
                    SourceType::BASE64,
                    $part['inlineData']['mimeType']
                );
            }
        }

        $message = new AssistantMessage(\array_values($blocks));
        $message->setUsage($usage);

        return $message;
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
