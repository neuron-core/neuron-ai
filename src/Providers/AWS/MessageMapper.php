<?php

declare(strict_types=1);

namespace NeuronAI\Providers\AWS;

use NeuronAI\Chat\Enums\SourceType;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ContentBlocks\AudioContent;
use NeuronAI\Chat\Messages\ContentBlocks\ContentBlockInterface;
use NeuronAI\Chat\Messages\ContentBlocks\FileContent;
use NeuronAI\Chat\Messages\ContentBlocks\ImageContent;
use NeuronAI\Chat\Messages\ContentBlocks\ReasoningContent;
use NeuronAI\Chat\Messages\ContentBlocks\TextContent;
use NeuronAI\Chat\Messages\ContentBlocks\VideoContent;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Exceptions\ProviderException;
use NeuronAI\Providers\MessageMapperInterface;
use stdClass;

use function array_map;
use function array_merge;
use function array_filter;
use function array_values;
use function base64_decode;
use function end;
use function explode;
use function preg_replace;
use function strtolower;
use function trim;
use function uniqid;

class MessageMapper implements MessageMapperInterface
{
    public function map(array $messages): array
    {
        $mapping = [];

        foreach ($messages as $message) {
            $mapping[] = match ($message::class) {
                Message::class,
                UserMessage::class,
                AssistantMessage::class => $this->mapMessage($message),
                ToolResultMessage::class => $this->mapToolCallResult($message),
                ToolCallMessage::class => $this->mapToolCall($message),
                default => throw new ProviderException('Could not map message type '.$message::class),
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
                        ],
                    ],
                    'toolUseId' => $tool->getCallId(),
                ],
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
                    // AWS Converse requires a JSON object; empty PHP array would serialize to [].
                    'input' => $tool->getInputs() !== [] ? $tool->getInputs() : new stdClass(),
                    'toolUseId' => $tool->getCallId(),
                ],
            ];
        }

        return [
            'role' => $message->getRole(),
            'content' => array_merge($this->mapBlocks($message->getContentBlocks()), $toolCallContents),
        ];
    }

    protected function mapMessage(Message $message): array
    {
        return [
            'role' => $message->getRole(),
            'content' => $this->mapBlocks($message->getContentBlocks()),
        ];
    }

    /**
     * @param ContentBlockInterface[] $blocks
     */
    protected function mapBlocks(array $blocks): array
    {
        return array_values(array_filter(array_map($this->mapContentBlock(...), $blocks)));
    }

    protected function mapContentBlock(ContentBlockInterface $block): ?array
    {
        return match ($block::class) {
            ReasoningContent::class => ['text' => $block->content, 'signature' => $block->id],
            TextContent::class => ['text' => $block->content],
            ImageContent::class => $this->mapImageBlock($block),
            FileContent::class => $this->mapFileBlock($block),
            AudioContent::class => $this->mapAudioBlock($block),
            VideoContent::class => $this->mapVideoBlock($block),
            default => null
        };
    }

    protected function mapImageBlock(ImageContent $block): ?array
    {
        $source = $this->mapMediaSource($block->sourceType, $block->content);
        if ($source === null) {
            return null;
        }

        return [
            'image' => [
                'format' => $this->extractFormat($block->mediaType),
                'source' => $source,
            ],
        ];
    }

    protected function mapFileBlock(FileContent $block): ?array
    {
        $source = $this->mapMediaSource($block->sourceType, $block->content);
        if ($source === null) {
            return null;
        }

        $format = $this->extractFormat($block->mediaType);

        return [
            'document' => [
                'format' => $format,
                'name' => $this->buildDocumentName($block->filename, $format),
                'source' => $source,
            ],
        ];
    }

    protected function buildDocumentName(?string $filename, ?string $format): string
    {
        $name = $filename ?? 'document-' . uniqid();
        // AWS Converse rule: alphanumeric, whitespace, hyphens, parentheses, square brackets only; no consecutive whitespace.
        $name = preg_replace('/[^a-zA-Z0-9\s\-()\[\]]/', '-', $name) ?? $name;
        $name = preg_replace('/\s+/', ' ', $name) ?? $name;
        $name = trim($name);

        return $name === '' ? 'document-' . uniqid() . ($format !== null ? '-' . $format : '') : $name;
    }

    protected function mapAudioBlock(AudioContent $block): ?array
    {
        $source = $this->mapMediaSource($block->sourceType, $block->content);
        if ($source === null) {
            return null;
        }

        return [
            'audio' => [
                'format' => $this->extractFormat($block->mediaType),
                'source' => $source,
            ],
        ];
    }

    protected function mapVideoBlock(VideoContent $block): ?array
    {
        $source = $this->mapMediaSource($block->sourceType, $block->content);
        if ($source === null) {
            return null;
        }

        return [
            'video' => [
                'format' => $this->extractFormat($block->mediaType),
                'source' => $source,
            ],
        ];
    }

    /**
     * @return array{bytes: string}|array{s3Location: array{uri: string}}|null
     */
    protected function mapMediaSource(SourceType $sourceType, string $content): ?array
    {
        return match ($sourceType) {
            SourceType::BASE64 => ['bytes' => base64_decode($content, true) ?: $content],
            SourceType::ID => ['s3Location' => ['uri' => $content]],
            SourceType::URL => null,
        };
    }

    protected function extractFormat(?string $mediaType): ?string
    {
        if ($mediaType === null) {
            return null;
        }

        $parts = explode('/', $mediaType);

        return strtolower(end($parts));
    }
}
