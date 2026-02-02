<?php

declare(strict_types=1);

namespace NeuronAI\Providers\Cohere;

use Generator;
use NeuronAI\Chat\Messages\ContentBlocks\ReasoningContent;
use NeuronAI\Chat\Messages\ContentBlocks\TextContent;
use NeuronAI\Chat\Messages\Stream\AssistantMessage;
use NeuronAI\Chat\Messages\Stream\Chunks\ReasoningChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronAI\Exceptions\ProviderException;
use NeuronAI\HttpClient\StreamInterface;
use NeuronAI\Providers\SSEParser;
use function array_key_exists;

trait HandleStream
{
    /**
     * @throws ProviderException
     */
    protected function processStream(StreamInterface $stream): Generator
    {
        $this->streamState = new StreamState();

        while (! $stream->eof()) {
            if (!$line = SSEParser::parseNextSSEEvent($stream)) {
                continue;
            }

            if ($line['type'] === 'message-start') {
                $this->streamState->messageId($line['id']);
            }

            // Capture usage information
            if (!empty($line['usage'])) {
                $this->streamState->addInputTokens($line['usage']['tokens']['input_tokens'] ?? 0);
                $this->streamState->addOutputTokens($line['usage']['tokens']['output_tokens'] ?? 0);
            }

            if ($line['type'] === 'tool-plan-delta') {
                $this->streamState->composeToolPlan($line['delta']['message']['tool_plan']);
            }

            // Compile tool calls
            if ($line['type'] === 'tool-call-start' || $line['type'] === 'tool-call-delta') {
                $this->streamState->composeToolCalls($line);
            }

            if ($line['type'] === 'tool-call-end') {
                $message = $this->createToolCallMessage(
                    $this->streamState->getToolCalls(),
                    new TextContent($this->streamState->getToolPlan())
                );
                $message->setUsage($this->streamState->getUsage());
                return $message;
            }

            if ($line['type'] === 'content-delta') {
                $content = $line['delta']['message']['content'];

                if (array_key_exists('text', $content)) {
                    $this->streamState->updateContentBlock(
                        $line['index'],
                        new TextContent($content['text'])
                    );
                    yield new TextChunk($this->streamState->messageId(), $content['text']);
                } elseif (array_key_exists('thinking', $content)) {
                    $this->streamState->updateContentBlock(
                        $line['index'],
                        new ReasoningContent($content['thinking'])
                    );
                    yield new ReasoningChunk($this->streamState->messageId(), $content['thinking']);
                }
            }
        }

        $message = new AssistantMessage($this->streamState->getContentBlocks());
        $message->setUsage($this->streamState->getUsage());

        return $message;
    }
}
