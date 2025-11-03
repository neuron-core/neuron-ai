<?php

declare(strict_types=1);

namespace NeuronAI\Chat\Messages\ContentBlocks;

use NeuronAI\Chat\Enums\ContentBlockType;

class ToolUseContent implements ContentBlock
{
    /**
     * @param string $id Tool call identifier
     * @param string $name Tool name
     * @param array<string, mixed> $input Tool arguments/parameters
     */
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly array $input
    ) {
    }

    public function getType(): ContentBlockType
    {
        return ContentBlockType::TOOL_USE;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'type' => $this->getType()->value,
            'id' => $this->id,
            'name' => $this->name,
            'input' => $this->input ?: new \stdClass(),
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
