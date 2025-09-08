<?php

declare(strict_types=1);

namespace NeuronAI\Chat\Messages;

use NeuronAI\Chat\Attachments\Attachment;
use NeuronAI\Chat\Enums\MessageRole;
use NeuronAI\StaticConstructor;

/**
 * @method static static make(MessageRole $role, array<int, mixed>|string|int|float|null $content = null)
 */
class Message implements \JsonSerializable
{
    use StaticConstructor;

    protected ?Usage $usage = null;

    /**
     * @var Attachment[]
     */
    protected array $attachments = [];

    /**
     * @var array<string, mixed>
     */
    protected array $meta = [];

    /**
     * @param array<int, mixed>|string|int|float|null $content
     */
    public function __construct(
        protected MessageRole $role,
        protected array|string|int|float|null $content = null
    ) {
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

    public function getContent(): mixed
    {
        return $this->content;
    }

    public function setContent(mixed $content): Message
    {
        $this->content = $content;
        return $this;
    }

    /**
     * @return array<Attachment>
     */
    public function getAttachments(): array
    {
        return $this->attachments;
    }

    public function addAttachment(Attachment $attachment): Message
    {
        $this->attachments[] = $attachment;
        return $this;
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
            'content' => $this->getContent()
        ];

        if ($this->getUsage() instanceof Usage) {
            $data['usage'] = $this->getUsage()->jsonSerialize();
        }

        if ($this->getAttachments() !== []) {
            $data['attachments'] = \array_map(fn (Attachment $attachment): array => $attachment->jsonSerialize(), $this->getAttachments());
        }

        return \array_merge($this->meta, $data);
    }
}
