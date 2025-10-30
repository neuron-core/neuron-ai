<?php

declare(strict_types=1);

namespace NeuronAI\Chat\Messages\ContentBlocks;

use NeuronAI\Chat\Enums\ContentBlockType;

class ToolResultContent implements ContentBlock
{
    /**
     * @param string $toolUseId Tool call identifier (references the corresponding ToolUseContentBlock)
     * @param mixed $content Tool execution result/content
     * @param bool $isError Whether this result represents an error
     */
    public function __construct(
        public readonly string $toolUseId,
        public readonly mixed $content,
        public readonly bool $isError = false
    ) {
    }

    public function getType(): ContentBlockType
    {
        return ContentBlockType::TOOL_RESULT;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'type' => $this->getType()->value,
            'tool_use_id' => $this->toolUseId,
            'content' => $this->content,
            'is_error' => $this->isError,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
