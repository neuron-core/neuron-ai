<?php

declare(strict_types=1);

namespace NeuronAI\Providers\Mistral;

use Generator;
use NeuronAI\Chat\Enums\MessageRole;
use NeuronAI\Chat\Enums\SourceType;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ContentBlocks\AudioContent;
use NeuronAI\Chat\Messages\ContentBlocks\FileContent;
use NeuronAI\Chat\Messages\ContentBlocks\ImageContent;
use NeuronAI\Chat\Messages\ContentBlocks\ReasoningContent;
use NeuronAI\Chat\Messages\ContentBlocks\TextContent;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\Stream\Chunks\ReasoningChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronAI\Exceptions\HttpException;
use NeuronAI\Exceptions\ProviderException;
use NeuronAI\HttpClient\HttpRequest;
use NeuronAI\Providers\OpenAI\StreamState;
use NeuronAI\Providers\SSEParser;

use function array_filter;
use function array_reduce;
use function array_unshift;
use function is_array;

trait HandleStream
{
    protected StreamState $streamState;

    /**
     * Stream response from the LLM.
     * https://docs.mistral.ai/api/endpoint/chat
     *
     * @throws ProviderException
     * @throws HttpException
     */
    public function stream(Message ...$messages): Generator
    {
        // Attach the system prompt
        if ($this->system !== null) {
            array_unshift($messages, new Message(MessageRole::SYSTEM, $this->system));
        }

        $body = [
            'stream' => true,
            'model' => $this->model,
            'messages' => $this->messageMapper()->map($messages),
            ...$this->parameters,
        ];

        // Attach tools
        if ($this->tools !== []) {
            $body['tools'] = $this->toolPayloadMapper()->map($this->tools);
        }

        $stream = $this->httpClient->stream(
            HttpRequest::post(
                uri: 'chat/completions',
                body: $body
            )
        );

        $this->streamState = new StreamState();

        while (! $stream->eof()) {
            if (($line = SSEParser::parseNextSSEEvent($stream)) === null) {
                continue;
            }

            // Capture usage information
            if (empty($line['choices']) && !empty($line['usage'])) {
                $this->streamState->addInputTokens($line['usage']['prompt_tokens'] ?? 0);
                $this->streamState->addOutputTokens($line['usage']['completion_tokens'] ?? 0);
                continue;
            }

            if (empty($line['choices'])) {
                continue;
            }

            $choice = $line['choices'][0];

            // Compile tool calls
            if ($this->isToolCallPart($line)) {
                $this->streamState->composeToolCalls($line);

                // Handle tool calls
                if ($choice['finish_reason'] === 'tool_calls') {
                    return $this->createToolCallMessage(
                        $this->streamState->getToolCalls(),
                        $this->streamState->getContentBlocks()
                    )->setUsage($this->streamState->getUsage());
                }

                continue;
            }

            // Process regular content
            $content = $choice['delta']['content'] ?? '';

            if (is_array($content)) {
                $content = $content[0];
                $block = match ($content['type']) {
                    'text' => new TextContent($content['text'] ?? ''),
                    'thinking' => new ReasoningContent(array_reduce(array_filter($content['thinking'], fn (array $item): bool => $item['type'] === 'text'), fn (string $carry, array $item): string => $carry .= $item['text'], '')),
                    'image_url' => new ImageContent(
                        $content['image_url']['url'] ?? '',
                        SourceType::BASE64
                    ),
                    'document_url' => new FileContent(
                        content: $content['document_url'] ?? '',
                        sourceType: SourceType::BASE64,
                        filename: $content['document_name'] ?? null
                    ),
                    'input_audio' => new AudioContent($content['input_audio'], SourceType::BASE64),
                    default => new TextContent(''),
                };
            } else {
                $block = new TextContent($content);
            }

            $this->streamState->updateContentBlock($choice['index'], $block);

            $chunk = match ($block::class) {
                TextContent::class => new TextChunk($this->streamState->messageId(), $block->getContent()),
                ReasoningContent::class => new ReasoningChunk($this->streamState->messageId(), $block->getContent()),
                default => null,
            };

            if ($chunk !== null) {
                yield $chunk;
            }
        }

        $message = new AssistantMessage($this->streamState->getContentBlocks());
        $message->setUsage($this->streamState->getUsage());

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
