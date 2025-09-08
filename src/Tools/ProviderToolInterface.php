<?php

declare(strict_types=1);

namespace NeuronAI\Tools;

interface ProviderToolInterface
{
    public function getType(): string;

    public function getName(): ?string;

    public function getOptions(): array;
}
