<?php

declare(strict_types=1);

namespace NeuronAI\Chat\Messages;

use NeuronAI\Tools\ToolCall;
use Stringable;

use function array_map;
use function array_merge;
use function json_encode;

/**
 * @method static static make(ToolCall[] $tools)
 */
class ToolResultMessage extends UserMessage implements Stringable
{
    /**
     * @param ToolCall[] $tools
     */
    public function __construct(protected array $tools)
    {
        parent::__construct(null);
    }

    /**
     * The settled calls this message answers — each carries its result (or its
     * rejection outcome) as conversation data.
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
                'type' => 'tool_call_result',
                'tools' => array_map(fn (ToolCall $tool): array => $tool->jsonSerialize(), $this->tools),
            ]
        );
    }

    public function __toString(): string
    {
        return (string) json_encode($this->getToolCalls());
    }
}
