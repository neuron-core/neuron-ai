<?php

declare(strict_types=1);

namespace NeuronAI\Tools;

use function array_filter;
use function array_values;

/**
 * The tools of one execution segment: whatever is registered is what the
 * model is offered and what may be executed.
 */
class ToolRegistry
{
    /**
     * @param array<ToolInterface|ProviderToolInterface> $tools
     */
    public function __construct(protected array $tools = [])
    {
    }

    /**
     * @return array<ToolInterface|ProviderToolInterface>
     */
    public function all(): array
    {
        return $this->tools;
    }

    public function find(string $name): ?ToolInterface
    {
        foreach ($this->tools as $tool) {
            if ($tool instanceof ToolInterface && $tool->getName() === $name) {
                return $tool;
            }
        }

        return null;
    }

    /**
     * Register a tool, unless a tool with the same name is registered already.
     */
    public function add(ToolInterface|ProviderToolInterface $tool): void
    {
        foreach ($this->tools as $registered) {
            if ($registered->getName() === $tool->getName()) {
                return;
            }
        }

        $this->tools[] = $tool;
    }

    public function remove(string $name): void
    {
        $this->tools = array_values(array_filter(
            $this->tools,
            fn (ToolInterface|ProviderToolInterface $tool): bool => $tool->getName() !== $name,
        ));
    }
}
