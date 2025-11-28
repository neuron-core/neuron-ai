<?php

declare(strict_types=1);

namespace NeuronAI\Chat\Messages;

use NeuronAI\Tools\ToolInterface;
use Stringable;

use function array_map;
use function array_merge;
use function json_encode;

/**
 * @method static static make(ToolInterface[] $tools)
 */
class ToolResultMessage extends UserMessage implements Stringable
{
    /**
     * @param ToolInterface[] $tools
     */
    public function __construct(protected array $tools)
    {
        parent::__construct(null);
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
                'type' => 'tool_call_result',
                'tools' => array_map(fn (ToolInterface $tool): array => $tool->jsonSerialize(), $this->tools)
            ]
        );
    }

    public function __toString(): string
    {
        return (string) json_encode($this->getTools());
    }
}
