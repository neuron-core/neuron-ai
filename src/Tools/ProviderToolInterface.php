<?php

declare(strict_types=1);

namespace NeuronAI\Tools;

use JsonSerializable;

interface ProviderToolInterface extends JsonSerializable
{
    public function getType(): string;

    public function getName(): ?string;

    public function getOptions(): array;

    public function canSee(bool $allow): ProviderToolInterface;

    public function canBeSeen(): bool;
}
