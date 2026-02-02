<?php

declare(strict_types=1);

namespace NeuronAI\Providers\Deepseek;

use Generator;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\Stream\AssistantMessage;
use NeuronAI\Chat\Messages\Stream\Chunks\ReasoningChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\StreamChunk;
use NeuronAI\Exceptions\HttpException;
use NeuronAI\Exceptions\ProviderException;
use NeuronAI\Providers\MessageMapperInterface;
use NeuronAI\Providers\OpenAI\OpenAI;

use function array_merge;
use function json_encode;

use const PHP_EOL;

class Deepseek extends OpenAI
{
    protected string $baseUri = "https://api.deepseek.com/v1";

    public function messageMapper(): MessageMapperInterface
    {
        return $this->messageMapper ?? $this->messageMapper = new MessageMapper();
    }

    /**
     * @param array<string, mixed> $response_format
     * @throws HttpException
     * @throws ProviderException
     */
    public function structured(
        array|Message $messages,
        string $class,
        array $response_format,
        bool $strict = false,
    ): Message {
        $this->parameters = array_merge($this->parameters, [
            'response_format' => [
                'type' => 'json_object',
            ]
        ]);

        $this->system .= PHP_EOL."# OUTPUT FORMAT CONSTRAINTS".PHP_EOL
            .'Generate a json respecting this schema: '.json_encode($response_format);

        return $this->chat($messages);
    }

    /**
     * Enrich messages with Deepseek-specific reasoning_content.
     * Works for both chat and streaming contexts.
     */
    protected function enrichMessage(AssistantMessage $message, ?array $response = null): AssistantMessage
    {
        // First apply parent enrichMessage (handles streaming metadata)
        $message = parent::enrichMessage($message);

        // For chat context: extract reasoning_content from API response
        if (isset($response['choices'][0]['message']['reasoning_content'])) {
            $reasoningContent = $response['choices'][0]['message']['reasoning_content'];
            if ($message->getMetadata('reasoning_content') === null) {
                $message->addMetadata('reasoning_content', $reasoningContent);
            }
        }

        return $message;
    }

    /**
     * Process Deepseek-specific delta content for tool calls (reasoning_content).
     * Accumulates metadata and yields ReasoningChunk for real-time streaming.
     *
     * @return Generator<StreamChunk>
     */
    protected function processToolCallDelta(array $choice): Generator
    {
        if (isset($choice['delta']['reasoning_content'])) {
            $reasoningContent = $choice['delta']['reasoning_content'];

            // Accumulate in metadata for final message
            $this->streamState->accumulateMetadata('reasoning_content', $reasoningContent);

            // Yield chunk for real-time streaming
            yield new ReasoningChunk($this->streamState->messageId(), $reasoningContent);
        }
    }

    /**
     * Process Deepseek-specific delta content for assistant messages (reasoning_content).
     * Accumulates metadata and yields ReasoningChunk for real-time streaming.
     *
     * @return Generator<StreamChunk>
     */
    protected function processContentDelta(array $choice): Generator
    {
        yield from parent::processContentDelta($choice);

        if (isset($choice['delta']['reasoning_content'])) {
            $reasoningContent = $choice['delta']['reasoning_content'];

            // Accumulate in metadata for the final message
            $this->streamState->accumulateMetadata('reasoning_content', $reasoningContent);

            // Yield chunk for real-time streaming
            yield new ReasoningChunk($this->streamState->messageId(), $reasoningContent);
        }
    }
}
