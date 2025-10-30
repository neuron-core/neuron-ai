<?php

declare(strict_types=1);

namespace NeuronAI\Chat\Messages;

use NeuronAI\Chat\Messages\ContentBlocks\ToolResultContent;
use NeuronAI\Tools\ToolInterface;

/**
 * @method static static make(ToolInterface[] $tools)
 */
class ToolResultMessage extends UserMessage implements \Stringable
{
    /**
     * @param ToolInterface[] $tools
     */
    public function __construct(protected array $tools)
    {
        // Create ToolResultContentBlock for each tool
        $contentBlocks = \array_map(
            fn (ToolInterface $tool): ToolResultContent => new ToolResultContent(
                toolUseId: $tool->getCallId() ?? '',
                content: $tool->getResult(),
                isError: false
            ),
            $tools
        );

        parent::__construct($contentBlocks);
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
        return \array_merge(
            parent::jsonSerialize(),
            [
                'type' => 'tool_call_result',
                'tools' => \array_map(fn (ToolInterface $tool): array => $tool->jsonSerialize(), $this->tools)
            ]
        );
    }

    public function __toString(): string
    {
        return (string) \json_encode($this->getTools());
    }
}
