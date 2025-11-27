<?php

declare(strict_types=1);

namespace NeuronAI\Tools;

use JsonSerializable;

interface ToolPropertyInterface extends JsonSerializable
{
    public function getName(): string;

    public function getType(): PropertyType;

    public function getDescription(): ?string;

    public function isRequired(): bool;

    public function getJsonSchema(): array;
}
