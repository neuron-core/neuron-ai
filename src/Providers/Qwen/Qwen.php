<?php

declare(strict_types=1);

namespace NeuronAI\Providers\Qwen;

use Generator;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ContentBlocks\ReasoningContent;
use NeuronAI\Chat\Messages\Stream\Chunks\ReasoningChunk;
use NeuronAI\HttpClient\StreamInterface;
use NeuronAI\Providers\OpenAI\OpenAI;
use NeuronAI\Providers\SSEParser;

class Qwen extends OpenAI
{
    protected string $baseUri = 'https://dashscope.aliyuncs.com/compatible-mode/v1';

    protected function processStream(StreamInterface $stream): Generator
    {
        while (! $stream->eof()) {
            if (! $line = SSEParser::parseNextSSEEvent($stream)) {
                continue;
            }

            $this->streamState->messageId($line['id'] ?? null);

            // Capture usage information
            if (! empty($line['usage'])) {
                $this->streamState->addInputTokens($line['usage']['prompt_tokens'] ?? 0);
                $this->streamState->addOutputTokens($line['usage']['completion_tokens'] ?? 0);
            }

            if (empty($line['choices'])) {
                continue;
            }

            $choice = $line['choices'][0];

            // Compile tool calls
            if (isset($choice['delta']['tool_calls'])) {
                $this->streamState->composeToolCalls($line);
                yield from $this->processToolCallDelta($choice);

                if ($this->finishForToolCall($choice)) {
                    goto toolcall;
                }

                continue;
            }

            // Handle tool calls
            if ($this->finishForToolCall($choice)) {
                toolcall:
                yield from $this->processToolCallDelta($choice);
                $message = $this->createToolCallMessage(
                    $this->streamState->getToolCalls(),
                    $this->streamState->getContentBlocks()
                );
                $message->setUsage($this->streamState->getUsage());
                $this->enrichMessage($message);

                return $message;
            }
            // Process reasoning content
            if ($choice['delta']['reasoning_content'] ?? false) {
                $this->streamState->updateContentBlock(
                    $choice['index'],
                    new ReasoningContent($choice['delta']['reasoning_content'])
                );
                yield new ReasoningChunk($this->streamState->messageId(), $choice['delta']['reasoning_content']);
            }

            // Process provider-specific delta content and yield custom chunks
            yield from $this->processContentDelta($choice);
        }

        // "enrichMessage" applies streamState metadata
        $message = new AssistantMessage($this->streamState->getContentBlocks());
        $message->setUsage($this->streamState->getUsage());
        $this->enrichMessage($message);

        return $message;
    }
}
