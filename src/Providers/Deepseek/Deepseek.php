<?php

declare(strict_types=1);

namespace NeuronAI\Providers\Deepseek;

use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Message;
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
     */
    public function structured(
        array $messages,
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
    protected function enrichMessage(Message $message, ?array $response = null): Message
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

        // For streaming: metadata is already applied by parent::enrichMessage()
        // via processContentDelta() and processToolCallDelta()

        return $message;
    }

    /**
     * Process Deepseek-specific delta content for tool calls (reasoning_content).
     */
    protected function processToolCallDelta(array $choice): void
    {
        if (isset($choice['delta']['reasoning_content'])) {
            $this->streamState->accumulateMetadata('reasoning_content', $choice['delta']['reasoning_content']);
        }
    }

    /**
     * Process Deepseek-specific delta content for assistant messages (reasoning_content).
     */
    protected function processContentDelta(array $choice): void
    {
        if (isset($choice['delta']['reasoning_content'])) {
            $this->streamState->accumulateMetadata('reasoning_content', $choice['delta']['reasoning_content']);
        }
    }
}
