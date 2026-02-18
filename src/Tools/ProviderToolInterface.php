<?php

declare(strict_types=1);

namespace NeuronAI\Tools;

use JsonSerializable;

interface ProviderToolInterface extends JsonSerializable
{
    public function getType(): string;

    public function getName(): ?string;

    public function getOptions(): array;

    public function authorize(bool $allow): ProviderToolInterface;

    public function isAuthorized(): bool;
}
