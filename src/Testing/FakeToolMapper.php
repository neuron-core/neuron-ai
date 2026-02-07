<?php

declare(strict_types=1);

namespace NeuronAI\Testing;

use NeuronAI\Providers\ToolMapperInterface;
use NeuronAI\Tools\ProviderToolInterface;
use NeuronAI\Tools\ToolInterface;

use function array_map;

class FakeToolMapper implements ToolMapperInterface
{
    /**
     * @param array<ToolInterface|ProviderToolInterface> $tools
     * @return array<array<string, mixed>>
     */
    public function map(array $tools): array
    {
        return array_map(
            static fn (ToolInterface|ProviderToolInterface $tool): array => $tool->jsonSerialize(),
            $tools
        );
    }
}
