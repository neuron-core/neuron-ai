<?php

namespace NeuronAI\Providers\ZAI;

use NeuronAI\Chat\Enums\SourceType;
use NeuronAI\Chat\Messages\ContentBlocks\ContentBlockInterface;
use NeuronAI\Chat\Messages\ContentBlocks\FileContent;
use NeuronAI\Chat\Messages\ContentBlocks\ImageContent;
use NeuronAI\Chat\Messages\ContentBlocks\TextContent;
use NeuronAI\Chat\Messages\ContentBlocks\VideoContent;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Providers\OpenAI\MessageMapper as OpenAIMessageMapper;

class MessageMapper extends OpenAIMessageMapper
{

    protected function mapMessage(Message $message): array
    {
        $result = [
            'role' => $message->getRole(),
            'content' => $this->mapBlocks($message->getContentBlocks()),
        ];

        if ($reasoning = $message->getReasoning()) {
            $result['reasoning_content'] = [
                'type' => 'reasoning',
                'content' => $reasoning->content
            ];
        }

        return $result;
    }

    protected function mapContentBlock(ContentBlockInterface $block): ?array
    {
        return match ($block::class) {
            TextContent::class => [
                'type' => 'text',
                'text' => $block->content,
            ],
            ImageContent::class => $this->mapImageBlock($block),
            FileContent::class => $this->mapFileBlock($block),
            VideoContent::class => $this->mapVideoBlock($block),
            default => null,
        };
    }

    protected function mapFileBlock(FileContent $block): ?array
    {
        return match ($block->sourceType) {
            SourceType::BASE64, SourceType::ID => null,
            SourceType::URL => [
                'type' => 'file_url',
                'file_url' => [
                    'url' => $block->content,
                ]
            ]
        };
    }

    protected function mapVideoBlock(VideoContent $block): ?array
    {
        return match ($block->sourceType) {
            SourceType::BASE64, SourceType::ID => null,
            SourceType::URL => [
                'type' => 'video_url',
                'video_url' => [
                    'url' => $block->content,
                ]
            ]
        };
    }
}
