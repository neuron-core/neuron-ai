<?php

declare(strict_types=1);

namespace NeuronAI\Providers\AWS;

use NeuronAI\Chat\Enums\SourceType;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ContentBlocks\AudioContent;
use NeuronAI\Chat\Messages\ContentBlocks\ContentBlock;
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
use NeuronAI\Tools\ToolOutput;
use stdClass;

use function array_map;
use function array_splice;
use function ksort;
use function array_filter;
use function array_merge;
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
    protected const CONVERSE_FORMATS = [
        'text/plain' => 'txt',
        'text/markdown' => 'md',
        'application/msword' => 'doc',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        'application/vnd.ms-excel' => 'xls',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
        'video/quicktime' => 'mov',
    ];

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
        foreach ($message->getToolCalls() as $tool) {
            $result = $tool->getResult();

            $toolContents[] = [
                'toolResult' => [
                    'content' => $result instanceof ToolOutput
                        ? ($this->mapBlocks($result->getBlocks()) ?: [['json' => ['result' => $result->getText()]]])
                        : [
                            [
                                'json' => [
                                    'result' => $result,
                                ],
                            ],
                        ],
                    'toolUseId' => $tool->getCallId(),
                    ...($result instanceof ToolOutput && $result->isError() ? ['status' => 'error'] : []),
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

        foreach ($message->getToolCalls() as $tool) {
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
            'content' => $this->mapMessageContent($message, $toolCallContents),
        ];
    }

    protected function mapMessageContent(Message $message, array $toolContents = []): array
    {
        // One group per block, so a cache point stays right after its block when
        // the insertions below go back to their positions.
        $contents = [];
        foreach ($message->getContentBlocks() as $block) {
            $mapped = $this->mapContentBlock($block);

            if ($mapped !== null) {
                $contents[] = $this->takesCachePoint($block) ? [$mapped, ['cachePoint' => ['type' => 'default']]] : [$mapped];
            }
        }

        $insertions = [];
        foreach ($message->getMetadata('aws_redacted_reasoning') ?? [] as $index => $data) {
            $insertions[$index] = [['reasoningContent' => ['redactedContent' => base64_decode($data)]]];
        }

        $toolPositions = $message->getMetadata('aws_tool_positions') ?? [];
        foreach ($toolContents as $index => $toolContent) {
            if (isset($toolPositions[$index])) {
                $insertions[$toolPositions[$index]] = [$toolContent];
            } else {
                $contents[] = [$toolContent];
            }
        }

        // Positions refer to the original response, including redacted blocks and tool calls.
        ksort($insertions);
        foreach ($insertions as $index => $content) {
            array_splice($contents, $index, 0, [$content]);
        }

        return array_merge(...$contents);
    }

    /**
     * Converse places a cache point as an entry of its own, after any block of a
     * message but a reasoning one.
     */
    protected function takesCachePoint(ContentBlockInterface $block): bool
    {
        return $block instanceof ContentBlock && !$block instanceof ReasoningContent && $block->isCached();
    }

    protected function mapMessage(Message $message): array
    {
        return [
            'role' => $message->getRole(),
            'content' => $this->mapMessageContent($message),
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
            ReasoningContent::class => [
                'reasoningContent' => [
                    'reasoningText' => [
                        'text' => $block->content,
                        ...($block->id === null ? [] : ['signature' => $block->id]),
                    ],
                ],
            ],
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
            SourceType::BASE64 => ['bytes' => $this->decodeBase64($content)],
            SourceType::ID => ['s3Location' => ['uri' => $content]],
            SourceType::URL => null,
        };
    }

    protected function decodeBase64(string $content): string
    {
        $decoded = base64_decode($content, true);

        return $decoded !== false ? $decoded : $content;
    }

    /**
     * Converse names formats from a fixed list; the MIME subtype matches it
     * for images and most video, but not for these types.
     */
    protected function extractFormat(?string $mediaType): ?string
    {
        if ($mediaType === null) {
            return null;
        }

        $mediaType = strtolower($mediaType);

        if (isset(self::CONVERSE_FORMATS[$mediaType])) {
            return self::CONVERSE_FORMATS[$mediaType];
        }

        $parts = explode('/', $mediaType);

        return end($parts);
    }
}
