<?php

declare(strict_types=1);

namespace NeuronAI\Tools;

use JsonSerializable;

interface ProviderToolInterface extends JsonSerializable
{
    public function getType(): string;

    public function getName(): ?string;

    public function getOptions(): array;

    public function visible(bool $visible): ProviderToolInterface;

    public function isVisible(): bool;
}
