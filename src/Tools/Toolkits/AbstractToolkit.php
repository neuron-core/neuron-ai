<?php

declare(strict_types=1);

namespace NeuronAI\Tools\Toolkits;

use NeuronAI\StaticConstructor;
use NeuronAI\Tools\ToolInterface;

abstract class AbstractToolkit implements ToolkitInterface
{
    use StaticConstructor;

    protected array $exclude = [];
    protected array $only = [];
    protected array $with = [];

    public function guidelines(): ?string
    {
        return null;
    }

    /**
     * @param  class-string[]  $classes
     */
    public function exclude(array $classes): ToolkitInterface
    {
        $this->exclude = $classes;
        return $this;
    }

    /**
     * @param  class-string[]  $classes
     */
    public function only(array $classes): ToolkitInterface
    {
        $this->only = $classes;
        return $this;
    }

    public function with(string $class, \Closure $callback): ToolkitInterface
    {
        $this->with[$class] = $callback;
        return $this;
    }

    /**
     * @return ToolInterface[]
     */
    abstract public function provide(): array;

    public function tools(): array
    {
        if ($this->exclude === [] && $this->only === [] && $this->with === []) {
            return $this->provide();
        }

        $tools = $this->provide();

        if ($this->exclude !== [] || $this->only !== []) {
            $tools = \array_filter(
                $tools,
                fn (ToolInterface $tool): bool => !\in_array($tool::class, $this->exclude)
                    && ($this->only === [] || \in_array($tool::class, $this->only))
            );
        }

        if ($this->with !== []) {
            return \array_map(fn (ToolInterface $tool): ToolInterface => $this->with[$tool::class]($tool) ?? $tool, $tools);
        }

        return $tools;
    }
}
