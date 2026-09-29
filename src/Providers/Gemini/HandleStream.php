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
use NeuronAI\Exceptions\HttpException;
use NeuronAI\Exceptions\ProviderException;
use NeuronAI\HttpClient\HttpRequest;
use NeuronAI\Providers\ProviderResponse;
use NeuronAI\Providers\SSEParser;
use NeuronAI\Tools\ToolInterface;

use function array_key_exists;
use function json_encode;
use function rtrim;

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
    public function stream(Message ...$messages): Generator
    {
        $body = [
            'contents' => $this->messageMapper()->map($messages),
            ...$this->parameters,
        ];

        if (isset($this->system)) {
            $body['system_instruction'] = [
                'parts' => [
                    ['text' => $this->system],
                ],
            ];
        }

        if (!empty($this->tools)) {
            $body['tools'] = $this->toolPayloadMapper()->map($this->tools);

            /*
             * When Gemini thinking models (e.g. 2.5 Pro) are given function tools, they can spontaneously invoke built-in provider-side tools
             * like run (code execution). Since these are never registered in NeuronAI's tool list, findTool() throws a ProviderException
             */
            foreach ($this->tools as $tool) {
                if ($tool instanceof ToolInterface) {
                    $body['toolConfig'] = [
                        'functionCallingConfig' => [
                            'mode' => 'AUTO',
                        ],
                    ];
                    break;
                }
            }
        }

        $stream = $this->httpClient->stream(
            HttpRequest::post(
                // SSE frames one element per line; the default JSON array has no line to split on
                uri: rtrim($this->baseUri, '/') . "/{$this->model}:streamGenerateContent?alt=sse",
                body: $body,
                headers: $this->requestHeaders(),
            )
        );

        $this->streamState = new StreamState();
        $lastFinishReason = null;

        while (! $stream->eof()) {
            if (!$line = SSEParser::parseNextSSEEvent($stream)) {
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
                $this->streamState->getUsage()->inputTokens = $line['usageMetadata']['promptTokenCount'] ?? 0;
                $this->streamState->getUsage()->outputTokens = $line['usageMetadata']['candidatesTokenCount'] ?? 0;
                $this->streamState->getUsage()->cachedInputTokens = $line['usageMetadata']['cachedContentTokenCount'] ?? 0;
                $this->streamState->getUsage()->reasoningTokens = $line['usageMetadata']['thoughtsTokenCount'] ?? 0;
            }

            if (isset($line['promptFeedback']['blockReason'])) {
                throw new ProviderException($this->describeBlockedPrompt($line['promptFeedback']));
            }

            // A usage-only element carries no candidate
            if (!isset($line['candidates'][0])) {
                continue;
            }

            // Track finishReason — the last value seen is authoritative
            if (isset($line['candidates'][0]['finishReason'])) {
                $lastFinishReason = $line['candidates'][0]['finishReason'];
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
                $message = $this->createToolCallMessage(
                    $this->streamState->getContentBlocks(),
                    $this->streamState->getToolCalls()
                )->setId($this->streamState->messageId())->setUsage($this->streamState->getUsage());
                return new ProviderResponse(message: $message);
            }

            if (array_key_exists('groundingMetadata', $line['candidates'][0])) {
                $citations = $this->extractCitations($line['candidates'][0]['groundingMetadata']);
            }

            // An element can carry several parts, e.g. reasoning then the answer
            foreach ($line['candidates'][0]['content']['parts'] ?? [] as $part) {
                yield from $this->handlePart($part);
            }
        }

        $message = new AssistantMessage($this->streamState->getContentBlocks());
        $message->setId($this->streamState->messageId())->setUsage($this->streamState->getUsage());

        if ($lastFinishReason !== null) {
            $message->setStopReason($lastFinishReason);
        }

        if (isset($citations)) {
            $message->addMetadata('citations', $citations);
        }

        return new ProviderResponse(message: $message);
    }

    /**
     * @param array<string, mixed> $part
     */
    protected function handlePart(array $part): Generator
    {
        if (isset($part['text'])) {
            yield from $this->handleTextData($part);
        } elseif (isset($part['inlineData'])) {
            $this->streamState->addContentBlock(new ImageContent(
                $part['inlineData']['data'],
                SourceType::BASE64,
                $part['inlineData']['mimeType']
            ));
        } elseif (isset($part['fileData'])) {
            $this->streamState->addContentBlock(new FileContent(
                $part['fileData']['fileUri'],
                SourceType::URL,
                $part['fileData']['mimeType']
            ));
        }
    }

    /**
     * @param array<string, mixed> $feedback
     */
    protected function describeBlockedPrompt(array $feedback): string
    {
        $description = "Gemini blocked the prompt: {$feedback['blockReason']}";

        return isset($feedback['blockReasonMessage']) ? "{$description} ({$feedback['blockReasonMessage']})" : $description;
    }

    protected function handleTextData(array $part): Generator
    {
        if ($part['thought'] ?? false) {
            // Accumulate the reasoning text
            $this->streamState->updateContentBlock('reasoning', $part['text']);
            if ($part['text'] !== '') {
                yield new ReasoningChunk($this->streamState->messageId(), $part['text']);
            }
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
}
