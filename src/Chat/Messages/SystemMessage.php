<?php

declare(strict_types=1);

namespace NeuronAI\Chat\Messages;

use NeuronAI\Chat\Enums\MessageRole;
use NeuronAI\Chat\Messages\ContentBlocks\ContentBlockInterface;
use NeuronAI\Chat\Messages\ContentBlocks\SystemContent;
use NeuronAI\Chat\Messages\ContentBlocks\TextContent;

use function array_map;
use function implode;
use function is_string;
use function str_contains;

/**
 * Carries the agent system instructions as a list of content blocks.
 *
 * @method static static make(string|ContentBlockInterface|array<int, ContentBlockInterface>|null $content = null)
 */
class SystemMessage extends Message
{
    /**
     * @param string|ContentBlockInterface|ContentBlockInterface[]|null $content
     */
    public function __construct(string|ContentBlockInterface|array|null $content = null)
    {
        parent::__construct(MessageRole::SYSTEM, $content);
    }

    /**
     * @param string|ContentBlockInterface|ContentBlockInterface[] $content
     */
    public function setContents(string|ContentBlockInterface|array $content): Message
    {
        if (is_string($content)) {
            $this->contents = [new SystemContent($content)];
            return $this;
        }

        return parent::setContents($content);
    }

    /**
     * Render the instructions as plain text for providers
     * without native system block support.
     */
    public function getContent(): ?string
    {
        $text = implode("\n\n", array_map(
            fn (TextContent $block): string => $block->content,
            $this->getTextBlocks()
        ));

        return $text === '' ? null : $text;
    }

    /**
     * Check if the given text appears in one of the content blocks.
     */
    public function contains(string $text): bool
    {
        foreach ($this->getTextBlocks() as $block) {
            if (str_contains($block->content, $text)) {
                return true;
            }
        }

        return false;
    }
}
