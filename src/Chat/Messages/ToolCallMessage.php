<?php

declare(strict_types=1);

namespace NeuronAI\Chat\Messages;

use NeuronAI\Chat\Messages\ContentBlocks\ContentBlockInterface;
use NeuronAI\Tools\ToolInterface;
use Stringable;

use function array_map;
use function array_merge;
use function json_encode;

/**
 * @method static static make(string|ContentBlockInterface|array<int, ContentBlockInterface>|null $content, ToolInterface[] $tools)
 */
class ToolCallMessage extends AssistantMessage implements Stringable
{
    /**
     * @param ToolInterface[] $tools
     */
    public function __construct(
        string|ContentBlockInterface|array|null $content = null,
        protected array                         $tools = []
    ) {
        parent::__construct($content);
    }

    /**
     * @return ToolInterface[]
     */
    public function getTools(): array
    {
        return $this->tools;
    }

    public function jsonSerialize(): array
    {
        return array_merge(
            parent::jsonSerialize(),
            [
                'type' => 'tool_call',
                'tools' => array_map(fn (ToolInterface $tool): array => $tool->jsonSerialize(), $this->tools)
            ]
        );
    }

    public function __toString(): string
    {
        return (string) json_encode($this->getTools());
    }
}
