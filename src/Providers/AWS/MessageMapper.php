<?php

declare(strict_types=1);

namespace NeuronAI\Providers\AWS;

use NeuronAI\Chat\Messages\ContentBlocks\ContentBlockInterface;
use NeuronAI\Chat\Messages\ContentBlocks\ReasoningContent;
use NeuronAI\Chat\Messages\ContentBlocks\TextContent;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Exceptions\ProviderException;
use NeuronAI\Providers\MessageMapperInterface;

class MessageMapper implements MessageMapperInterface
{
    public function map(array $messages): array
    {
        $mapping = [];

        foreach ($messages as $message) {
            $mapping[] = match ($message::class) {
                ToolResultMessage::class => $this->mapToolCallResult($message),
                ToolCallMessage::class => $this->mapToolCall($message),
                default => $this->mapMessage($message),
            };
        }

        return $mapping;
    }

    protected function mapToolCallResult(ToolResultMessage $message): array
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

        $blocks = \array_map($this->mapContentBlock(...), $message->getContentBlocks());

        return [
            'role' => $message->getRole(),
            'content' => \array_merge($blocks, $toolCallContents),
        ];
    }

    protected function mapMessage(Message $message): array
    {
        $contentBlocks = $message->getContentBlocks();

        return [
            'role' => $message->getRole(),
            'content' => \array_map($this->mapContentBlock(...), $contentBlocks)
        ];
    }

    /**
     * @throws ProviderException
     */
    protected function mapContentBlock(ContentBlockInterface $block): array
    {
        if ($block instanceof ReasoningContent) {
            return ['text' => $block->content, 'signature' => $block->id];
        }

        if ($block instanceof TextContent) {
            return ['text' => $block->content];
        }

        throw new ProviderException('Unsupported content block type: '.$block::class);
    }
}
