<?php

declare(strict_types=1);

namespace NeuronAI\Tools;

use NeuronAI\Exceptions\ToolException;

use function array_filter;
use function array_values;

/**
 * The tools of one execution segment: whatever is registered is what the
 * model is offered and what may be executed.
 */
class ToolRegistry
{
    /**
     * @var array<ToolInterface|ProviderToolInterface>
     */
    protected array $tools = [];

    /**
     * @param array<ToolInterface|ProviderToolInterface> $tools
     * @throws ToolException When two tools share a name: the provider would reject them, or the model could not tell them apart.
     */
    public function __construct(array $tools = [])
    {
        foreach ($tools as $tool) {
            if ($this->has($tool)) {
                throw new ToolException("Tool names must be unique: \"{$tool->getName()}\" is registered twice.");
            }

            $this->tools[] = $tool;
        }
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
     * Register a tool, unless it or a tool with the same name is registered already.
     */
    public function add(ToolInterface|ProviderToolInterface $tool): void
    {
        if (!$this->has($tool)) {
            $this->tools[] = $tool;
        }
    }

    /**
     * Unnamed provider tools, such as a built-in web search, never clash by name.
     */
    protected function has(ToolInterface|ProviderToolInterface $tool): bool
    {
        foreach ($this->tools as $registered) {
            if ($registered === $tool || ($tool->getName() !== null && $registered->getName() === $tool->getName())) {
                return true;
            }
        }

        return false;
    }

    public function remove(string $name): void
    {
        $this->tools = array_values(array_filter(
            $this->tools,
            fn (ToolInterface|ProviderToolInterface $tool): bool => $tool->getName() !== $name,
        ));
    }
}
