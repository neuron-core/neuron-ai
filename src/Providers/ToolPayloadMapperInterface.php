<?php

declare(strict_types=1);

namespace NeuronAI\Providers;

use NeuronAI\Tools\ProviderToolInterface;
use NeuronAI\Tools\ToolInterface;

interface ToolPayloadMapperInterface
{
    /**
     * @param array<ToolInterface|ProviderToolInterface> $tools
     */
    public function map(array $tools): array;
}
