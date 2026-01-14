<?php

declare(strict_types=1);

namespace NeuronAI\Providers\Gemini;

use Generator;
use NeuronAI\Chat\Enums\SourceType;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ContentBlocks\FileContent;
use NeuronAI\Chat\Messages\ContentBlocks\ImageContent;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\Stream\Chunks\ReasoningChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronAI\Exceptions\ProviderException;
use NeuronAI\Exceptions\HttpException;
use NeuronAI\HttpClient\HttpRequest;
use NeuronAI\HttpClient\StreamInterface;

use function array_key_exists;
use function json_decode;
use function mb_strlen;
use function rtrim;
use function trim;
use function is_array;
use function json_encode;

trait HandleStream
{
    protected StreamState $streamState;

    /**
     * Stream response from the LLM.
     *
     * https://ai.google.dev/api/live#messages
     *
     * @throws ProviderException
     * @throws HttpException
     */
    public function stream(array|Message $messages): Generator
    {
        $json = [
            'contents' => $this->messageMapper()->map(is_array($messages) ? $messages : [$messages]),
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

        $stream = $this->httpClient->stream(
            HttpRequest::post(
                uri: trim($this->baseUri, '/')."/{$this->model}:streamGenerateContent",
                body: $json
            )
        );

        $this->streamState = new StreamState();

        while (! $stream->eof()) {
            $line = $this->readLine($stream);

            if (($line = json_decode((string) $line, true)) === null) {
                continue;
            }

            if (array_key_exists('error', $line)) {
                throw new ProviderException("Gemini API Error (Streaming): " . ($line['error']['message'] ?? json_encode($line['error'])));
            }

            // Save usage information
            if (array_key_exists('usageMetadata', $line) &&
                array_key_exists('promptTokenCount', $line['usageMetadata']) &&
                array_key_exists('candidatesTokenCount', $line['usageMetadata'])
            ) {
                $this->streamState->addInputTokens($line['usageMetadata']['promptTokenCount'] ?? 0);
                $this->streamState->addOutputTokens($line['usageMetadata']['candidatesTokenCount'] ?? 0);
            }

            // Process tool calls
            if ($this->hasToolCalls($line)) {
                $this->streamState->composeToolCalls($line);

                // Gemini 2.5 includes the finish reason in the tool call message. Gemini 3 uses a separate message instead.
                if (isset($line['candidates'][0]['finishReason']) && $line['candidates'][0]['finishReason'] === 'STOP') {
                    goto toolcall;
                }
                continue;
            }

            // Handle tool calls when finished
            if (
                isset($line['candidates'][0]['finishReason']) &&
                $line['candidates'][0]['finishReason'] === 'STOP' &&
                $this->streamState->hasToolCalls()
            ) {
                toolcall:
                return $this->createToolCallMessage(
                    $this->streamState->getContentBlocks(),
                    $this->streamState->getToolCalls()
                )->setUsage($this->streamState->getUsage());
            }

            // Process content
            if (! ($part = $line['candidates'][0]['content']['parts'][0] ?? null)) {
                continue;
            }

            if (isset($part['text'])) {
                yield from $this->handleTextData($part);
                continue;
            }

            if (isset($part['inlineData'])) {
                $this->streamState->addContentBlock('image', new ImageContent(
                    $part['inlineData']['data'],
                    SourceType::BASE64,
                    $part['inlineData']['mimeType']
                ));
                continue;
            }

            if (isset($part['fileData'])) {
                $this->streamState->addContentBlock('file', new FileContent(
                    $part['fileData']['fileUri'],
                    SourceType::URL,
                    $part['fileData']['mimeType']
                ));
            }
        }

        $message = new AssistantMessage($this->streamState->getContentBlocks());
        $message->setUsage($this->streamState->getUsage());

        return $message;
    }

    protected function handleTextData(array $part): Generator
    {
        if ($part['thought'] ?? false) {
            // Accumulate the reasoning text
            $this->streamState->updateContentBlock('reasoning', $part['text']);
            yield new ReasoningChunk($this->streamState->messageId(), $part['text']);
        } else {
            // Accumulate simple text output
            $this->streamState->updateContentBlock('text', $part['text']);
            yield new TextChunk($this->streamState->messageId(), $part['text']);
        }
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

            if (mb_strlen($buffer) === 1 && $buffer !== '{') {
                $buffer = '';
            }

            if (json_decode($buffer) !== null) {
                return $buffer;
            }
        }

        return rtrim($buffer, ']');
    }
}
