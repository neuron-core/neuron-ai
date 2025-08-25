<?php

declare(strict_types=1);

namespace NeuronAI\Providers\AWSBedrock;

use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolCallResultMessage;
use NeuronAI\Providers\MessageMapperInterface;

class BedrockRuntimeMessageMapper implements MessageMapperInterface
{
    protected array $mapping = [];

    public function map(array $messages): array
    {
        $this->mapping = [];

        foreach ($messages as $message) {
            match ($message::class) {
                ToolCallResultMessage::class => $this->mapToolCallResult($message),
                ToolCallMessage::class => $this->mapToolCall($message),
                default => $this->mapMessage($message),
            };
        }

        return $this->mapping;
    }

    protected function mapToolCallResult(ToolCallResultMessage $message): void
    {
        $toolContents = [];
        foreach ($message->getTools() as $tool) {
            $toolContents[] = [
                'toolResult' => [
                    'content' => [
                        [
                            'json' => [
                                'result' => $tool->getResult(),
                            ],
                        ]
                    ],
                    'toolUseId' => $tool->getCallId(),
                ]
            ];
        }

        $this->mapping[] = [
            'role' => $message->getRole(),
            'content' => $toolContents,
        ];
    }

    protected function mapToolCall(ToolCallMessage $message): void
    {
        $toolCallContents = [];

        foreach ($message->getTools() as $tool) {
            $toolCallContents[] = [
                'toolUse' => [
                    'name' => $tool->getName(),
                    'input' => $tool->getInputs(),
                    'toolUseId' => $tool->getCallId(),
                ],
            ];
        }

        $this->mapping[] = [
            'role' => $message->getRole(),
            'content' => $toolCallContents,
        ];
    }

    protected function mapMessage(Message $message): void
    {
        $this->mapping[] = [
            'role' => $message->getRole(),
            'content' => [['text' => $message->getContent()]],
        ];
    }
}
