<?php

declare(strict_types=1);

namespace NeuronAI\Providers\Deepseek;

use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Providers\OpenAI\OpenAI;

use function array_merge;
use function json_encode;

use const PHP_EOL;

class Deepseek extends OpenAI
{
    protected string $baseUri = "https://api.deepseek.com/v1";

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

    protected function createToolCallMessage(array $message): ToolCallMessage
    {
        $result = parent::createToolCallMessage($message);

        if (isset($message['reasoning_content'])) {
            $result->addMetadata('reasoning_content', $message['reasoning_content']);
        }

        return $result;
    }

    protected function createAssistantMessage(array $response): AssistantMessage
    {
        $message = parent::createAssistantMessage($response);

        if (isset($response['choices'][0]['message']['reasoning_content'])) {
            $message->addMetadata('reasoning_content', $response['choices'][0]['message']['reasoning_content']);
        }

        return $message;
    }
}
