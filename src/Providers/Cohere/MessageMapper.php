<?php

declare(strict_types=1);

namespace NeuronAI\Providers\Cohere;

use NeuronAI\Chat\Enums\MessageRole;
use NeuronAI\Chat\Messages\ContentBlocks\ContentBlockInterface;
use NeuronAI\Chat\Messages\ContentBlocks\FileContent;
use NeuronAI\Chat\Messages\ContentBlocks\ReasoningContent;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Providers\OpenAI\MessageMapper as OpenAIMessageMapper;
use NeuronAI\Tools\ToolInterface;

class MessageMapper extends OpenAIMessageMapper
{
    protected function mapContentBlock(ContentBlockInterface $block): ?array
    {
        return match ($block::class) {
            FileContent::class => null,
            ReasoningContent::class => [
                'type' => 'thinking',
                'thinking' => $block->content,
            ],
            default => parent::mapContentBlock($block),
        };
    }

    protected function mapToolCall(ToolCallMessage $message): array
    {
        return [
            'role' => MessageRole::ASSISTANT,
            'tool_plan' => $message->getContent(),
            'tool_calls' => array_map(fn (ToolInterface $tool): array => [
                'id' => $tool->getCallId(),
                'type' => 'function',
                'function' => [
                    'name' => $tool->getName(),
                    'arguments' => $tool->getInputs() === [] ? '{}' : json_encode($tool->getInputs()),
                ],
            ], $message->getTools())
        ];
    }

    protected function mapToolsResult(ToolResultMessage $message): array
    {
        return array_map(fn (ToolInterface $tool): array => [
            'role' => MessageRole::TOOL,
            'tool_call_id' => $tool->getCallId(),
            'content' => [
                [
                    'type' => 'text',
                    'text' => $tool->getResult(),
                ]
            ]
        ], $message->getTools());
    }
}
