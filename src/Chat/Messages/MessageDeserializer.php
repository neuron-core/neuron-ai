<?php

declare(strict_types=1);

namespace NeuronAI\Chat\Messages;

use NeuronAI\Chat\Enums\ContentBlockType;
use NeuronAI\Chat\Enums\MessageRole;
use NeuronAI\Chat\Enums\SourceType;
use NeuronAI\Chat\Messages\ContentBlocks\AudioContent;
use NeuronAI\Chat\Messages\ContentBlocks\ContentBlockInterface;
use NeuronAI\Chat\Messages\ContentBlocks\FileContent;
use NeuronAI\Chat\Messages\ContentBlocks\ImageContent;
use NeuronAI\Chat\Messages\ContentBlocks\ReasoningContent;
use NeuronAI\Chat\Messages\ContentBlocks\SystemContent;
use NeuronAI\Chat\Messages\ContentBlocks\TextContent;
use NeuronAI\Chat\Messages\ContentBlocks\VideoContent;
use NeuronAI\Tools\ApprovalState;
use NeuronAI\Tools\ToolCall;
use NeuronAI\Tools\ToolOutput;

use function array_map;
use function in_array;
use function is_array;
use function is_string;
use function json_decode;

/**
 * Rebuilds a message from the array produced by Message::jsonSerialize().
 * Shapes stored by earlier versions still deserialize.
 */
class MessageDeserializer
{
    /**
     * Keys the message classes serialize from their own state, never metadata.
     */
    protected const STRUCTURAL_KEYS = ['role', 'content', 'usage', 'type', 'tools'];

    /**
     * @param array<string, mixed> $data
     */
    public function deserialize(array $data): Message
    {
        return match ($data['type'] ?? null) {
            'tool_call' => $this->deserializeToolCall($data),
            'tool_call_result' => $this->deserializeToolCallResult($data),
            default => $this->deserializeMessage($data),
        };
    }

    /**
     * @param array<string, mixed> $message
     */
    protected function deserializeMessage(array $message): Message
    {
        $role = MessageRole::from($message['role']);
        $content = $this->deserializeContent($message['content'] ?? null);

        $item = match ($role) {
            MessageRole::ASSISTANT => new AssistantMessage($content),
            MessageRole::USER => new UserMessage($content),
            default => new Message($role, $content)
        };

        $this->deserializeMeta($message, $item);

        return $item;
    }

    /**
     * @param array<string, mixed> $message
     */
    protected function deserializeToolCall(array $message): ToolCallMessage
    {
        $tools = array_map(function (array $tool): ToolCall {
            // Legacy histories may carry schema-side keys (e.g. 'parameters');
            // they are ignored — a call record needs no schema.
            $call = new ToolCall(
                $tool['name'],
                $tool['callId'] ?? null,
                $tool['inputs'],
                $tool['description'] ?? null,
                $tool['deferred'] ?? false,
            );
            $call->setApprovalReason($tool['approvalReason'] ?? null);

            if (isset($tool['approval'])) {
                $call->setApprovalState(
                    ApprovalState::from($tool['approval']),
                    $tool['rejectReason'] ?? null
                );
            }

            return $call;
        }, $message['tools']);

        $item = new ToolCallMessage(tools: $tools);

        $this->deserializeMeta($message, $item);

        if ($content = $this->deserializeContent($message['content'] ?? null)) {
            $item->setContents($content);
        }

        return $item;
    }

    /**
     * @param array<string, mixed> $message
     */
    protected function deserializeToolCallResult(array $message): ToolResultMessage
    {
        $tools = array_map(function (array $tool): ToolCall {
            $call = new ToolCall(
                $tool['name'],
                $tool['callId'] ?? null,
                $tool['inputs'],
                $tool['description'] ?? null,
                $tool['deferred'] ?? false,
            );
            $call->setResult($this->deserializeToolResult($tool['result']))
                ->setApprovalReason($tool['approvalReason'] ?? null);

            if (isset($tool['approval'])) {
                $call->setApprovalState(
                    ApprovalState::from($tool['approval']),
                    $tool['rejectReason'] ?? null
                );
            }

            return $call;
        }, $message['tools']);

        $item = new ToolResultMessage($tools);

        $this->deserializeMeta($message, $item);

        return $item;
    }

    /**
     * A multimodal result is stored as content blocks, an error result wraps them
     * under an 'is_error' marker, plain text stays a string. Legacy histories never
     * carry the marker and deserialize as non-error.
     */
    protected function deserializeToolResult(mixed $result): string|ToolOutput
    {
        if (is_array($result)) {
            if (isset($result['is_error'])) {
                $blocks = $result['blocks'] ?? [];
                return new ToolOutput(
                    array_map(
                        $this->deserializeContentBlock(...),
                        isset($blocks[0]['type']) ? $blocks : []
                    ),
                    (bool) $result['is_error']
                );
            }

            return new ToolOutput(array_map(
                $this->deserializeContentBlock(...),
                isset($result[0]['type']) ? $result : []
            ));
        }

        return (string) $result;
    }

    /**
     * Legacy string content is converted to TextContent for migration;
     * the current format is an array of content blocks.
     *
     * @return string|ContentBlockInterface|ContentBlockInterface[]|null
     */
    protected function deserializeContent(mixed $content): string|ContentBlockInterface|array|null
    {
        if ($content === null) {
            return null;
        }

        if (is_string($content)) {
            if ($json = json_decode($content, true)) {
                return $this->deserializeContent($json);
            }
            return new TextContent($content);
        }

        if (is_array($content)) {
            if (isset($content[0]['type'])) {
                return array_map($this->deserializeContentBlock(...), $content);
            }

            if ($content === []) {
                return null;
            }
        }

        return new TextContent((string) $content);
    }

    /**
     * @param array<string, mixed> $block
     */
    protected function deserializeContentBlock(array $block): ContentBlockInterface
    {
        $type = ContentBlockType::from($block['type']);

        $item = match ($type) {
            ContentBlockType::TEXT => new TextContent(
                content: $block['content']
            ),
            ContentBlockType::REASONING => new ReasoningContent(
                content: $block['content'],
                id: $block['id'] ?? null
            ),
            ContentBlockType::SYSTEM => new SystemContent(
                content: $block['content']
            ),
            ContentBlockType::IMAGE => new ImageContent(
                content: $block['content'],
                sourceType: SourceType::from($block['source_type']),
                mediaType: $block['media_type'] ?? null
            ),
            ContentBlockType::FILE => new FileContent(
                content: $block['content'],
                sourceType: SourceType::from($block['source_type']),
                mediaType: $block['media_type'] ?? null,
                filename: $block['filename'] ?? null
            ),
            ContentBlockType::AUDIO => new AudioContent(
                content: $block['content'],
                sourceType: SourceType::from($block['source_type']),
                mediaType: $block['media_type'] ?? null
            ),
            ContentBlockType::VIDEO => new VideoContent(
                content: $block['content'],
                sourceType: SourceType::from($block['source_type']),
                mediaType: $block['media_type'] ?? null
            ),
        };

        if (isset($block['meta'])) {
            $item->setMetadata($block['meta']);
        }

        return $item;
    }

    /**
     * @param array<string, mixed> $message
     */
    protected function deserializeMeta(array $message, Message $item): void
    {
        if (isset($message['usage'])) {
            $item->setUsage(
                new Usage($message['usage']['input_tokens'], $message['usage']['output_tokens'])
            );
        }

        foreach ($message as $key => $value) {
            if (in_array($key, self::STRUCTURAL_KEYS, true)) {
                continue;
            }
            if ($key === 'citations' && is_array($value)) {
                $citations = array_map(
                    Citation::fromArray(...),
                    $value
                );
                $item->addMetadata($key, $citations);
                continue;
            }
            $item->addMetadata($key, $value);
        }
    }
}
