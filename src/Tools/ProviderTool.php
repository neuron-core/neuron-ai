<?php

declare(strict_types=1);

namespace NeuronAI\Tools;

use NeuronAI\StaticConstructor;

/**
 * @method static static make(string $type, ?string $name = null, array $options = [])
 */
class ProviderTool implements ProviderToolInterface
{
    use StaticConstructor;

    public function __construct(
        protected string $type,
        protected ?string $name = null,
        protected array $options = [],
    ) {
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function getOptions(): array
    {
        return $this->options;
    }

    public function setOptions(array $options): self
    {
        $this->options = $options;
        return $this;
    }

    public function jsonSerialize(): array
    {
        return [
            'type' => $this->type,
            'name' => $this->name,
            'options' => $this->options,
        ];
    }
}
