<?php

namespace NeuronAI\Chat\Messages;

class Message implements \JsonSerializable
{
    const ROLE_USER = 'user';
    const ROLE_ASSISTANT = 'assistant';
    const ROLE_MODEL = 'model';
    const ROLE_TOOL = 'tool';
    const ROLE_SYSTEM = 'system';

    protected ?Usage $usage = null;
    protected array $images = [];

    protected array $meta = [];

    public function __construct(
        protected string $role,
        protected array|string|int|float|null $content = null
    ) {}

    public function getRole(): string
    {
        return $this->role;
    }

    public function setRole(string $role): Message
    {
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
     * @return array<Image>
     */
    public function getImages(): array
    {
        return $this->images;
    }

    public function addImage(Image $image): Message
    {
        $this->images[] = $image;
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

    public function addMetadata(string $key, string|array|null $value): Message
    {
        $this->meta[$key] = $value;
        return $this;
    }

    public function jsonSerialize(): array
    {
        $data = [
            'role' => $this->getRole(),
            'content' => $this->getContent()
        ];

        if ($this->getUsage()) {
            $data['usage'] = $this->getUsage()->jsonSerialize();
        }

        if (!empty($this->getImages())) {
            $data['images'] = \array_map(fn (Image $image) => $image->jsonSerialize(), $this->getImages());
        }

        return \array_merge($this->meta, $data);
    }
}
