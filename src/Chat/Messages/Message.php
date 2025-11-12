<?php

declare(strict_types=1);

namespace NeuronAI\Chat\Messages;

use NeuronAI\Chat\Messages\ContentBlocks\ContentBlock;
use NeuronAI\Chat\Messages\ContentBlocks\ReasoningContent;
use NeuronAI\Chat\Messages\ContentBlocks\TextContent;
use NeuronAI\Chat\Enums\MessageRole;
use NeuronAI\StaticConstructor;

/**
 * @method static static make(MessageRole $role, string|ContentBlock|ContentBlock[]|null $content = null)
 */
class Message implements \JsonSerializable
{
    use StaticConstructor;

    protected ?Usage $usage = null;

    /**
     * @var ContentBlock[]
     */
    protected array $contents = [];

    /**
     * @var array<string, mixed>
     */
    protected array $meta = [];

    /**
     * @param string|ContentBlock|ContentBlock[]|null $content
     */
    public function __construct(
        protected MessageRole $role,
        string|ContentBlock|array|null $content = null
    ) {
        if ($content !== null) {
            $this->setContents($content);
        }
    }

    public function getRole(): string
    {
        return $this->role->value;
    }

    public function setRole(MessageRole|string $role): Message
    {
        if (!$role instanceof MessageRole) {
            $role = MessageRole::from($role);
        }

        $this->role = $role;
        return $this;
    }

    /**
     * @return ContentBlock[]
     */
    public function getContentBlocks(): array
    {
        return $this->contents;
    }

    /**
     * @param string|ContentBlock|ContentBlock[] $content
     */
    public function setContents(string|ContentBlock|array $content): Message
    {
        if (\is_string($content)) {
            $this->contents = [new TextContent($content)];
        } elseif ($content instanceof ContentBlock) {
            $this->contents = [$content];
        } else {
            // Assume it's an array
            foreach ($content as $block) {
                $this->addContent($block);
            }
        }

        return $this;
    }

    public function addContent(ContentBlock $block): Message
    {
        $this->contents[] = $block;

        return $this;
    }

    /**
     * Get the text content of the message.
     */
    public function getContent(): string
    {
        $text = '';
        foreach ($this->contents as $index => $block) {
            if ($block instanceof TextContent) {
                $text .= ($index > 0 ? " " : '').$block->text;
            }
            if ($block instanceof ReasoningContent) {
                $text .= ($index > 0 ? "\n\n" : '').$block->text."\n\n";
            }
        }

        return $text;
    }

    public function getUsage(): ?Usage
    {
        return $this->usage;
    }

    public function setUsage(Usage $usage): static
    {
        $this->usage = $usage;
        return $this;
    }

    /**
     * @param string|array<int, mixed>|null $value
     */
    public function addMetadata(string $key, string|array|null $value): Message
    {
        $this->meta[$key] = $value;
        return $this;
    }

    public function getMetadata(string $key): mixed
    {
        return $this->meta[$key] ?? null;
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        $data = [
            'role' => $this->getRole(),
            'content' => \array_map(fn (ContentBlock $block): array => $block->toArray(), $this->contents)
        ];

        if ($this->getUsage() instanceof Usage) {
            $data['usage'] = $this->getUsage()->jsonSerialize();
        }

        return \array_merge($this->meta, $data);
    }
}
