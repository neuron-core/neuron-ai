<?php

declare(strict_types=1);

namespace NeuronAI\Providers\AWS;

use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolCallResultMessage;
use NeuronAI\Providers\MessageMapperInterface;

class MessageMapper implements MessageMapperInterface
{
    public function map(array $messages): array
    {
        $mapping = [];

        foreach ($messages as $message) {
            $mapping[] = match ($message::class) {
                ToolCallResultMessage::class => $this->mapToolCallResult($message),
                ToolCallMessage::class => $this->mapToolCall($message),
                default => $this->mapMessage($message),
            };
        }

        return $mapping;
    }

    protected function mapToolCallResult(ToolCallResultMessage $message): array
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

        return [
            'role' => $message->getRole(),
            'content' => $toolContents,
        ];
    }

    protected function mapToolCall(ToolCallMessage $message): array
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

        return [
            'role' => $message->getRole(),
            'content' => $toolCallContents,
        ];
    }

    protected function mapMessage(Message $message): array
    {
        return [
            'role' => $message->getRole(),
            'content' => [['text' => $message->getContent()]],
        ];
    }
}
