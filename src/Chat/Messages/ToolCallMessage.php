<?php

declare(strict_types=1);

namespace NeuronAI\Chat\Messages;

use NeuronAI\Chat\Messages\ContentBlocks\ContentBlockInterface;
use NeuronAI\Tools\ToolCall;
use Stringable;

use function array_map;
use function array_merge;
use function json_encode;

/**
 * @method static static make(string|ContentBlockInterface|array<int, ContentBlockInterface>|null $content, ToolCall[] $tools)
 */
class ToolCallMessage extends AssistantMessage implements Stringable
{
    /**
     * @param ToolCall[] $tools
     */
    public function __construct(
        string|ContentBlockInterface|array|null $content = null,
        protected array $tools = []
    ) {
        parent::__construct($content);
    }

    /**
     * The calls carried by this message — pure conversation data.
     * Execution capability lives on the agent's live tool registry, never here.
     *
     * @return ToolCall[]
     */
    public function getToolCalls(): array
    {
        return $this->tools;
    }

    public function jsonSerialize(): array
    {
        return array_merge(
            parent::jsonSerialize(),
            [
                'type' => 'tool_call',
                'tools' => array_map(fn (ToolCall $tool): array => $tool->jsonSerialize(), $this->tools),
            ]
        );
    }

    public function __toString(): string
    {
        return (string) json_encode($this->getToolCalls());
    }
}
