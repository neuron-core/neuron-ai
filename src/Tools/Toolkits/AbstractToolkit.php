<?php

declare(strict_types=1);

namespace NeuronAI\Tools\Toolkits;

use NeuronAI\StaticConstructor;
use NeuronAI\Tools\ToolInterface;

use function array_filter;
use function array_map;
use function array_values;
use function in_array;

abstract class AbstractToolkit implements ToolkitInterface
{
    use StaticConstructor;

    protected array $exclude = [];
    protected array $only = [];
    protected array $with = [];
    protected array $added = [];

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

    public function with(string $class, callable $callback): ToolkitInterface
    {
        $this->with[$class] = $callback;
        return $this;
    }

    public function add(ToolInterface ...$tools): ToolkitInterface
    {
        $this->added = [...$this->added, ...array_values($tools)];
        return $this;
    }

    /**
     * @return ToolInterface[]
     */
    abstract public function provide(): array;

    public function tools(): array
    {
        $tools = [...$this->provide(), ...$this->added];

        if ($this->exclude !== [] || $this->only !== []) {
            $tools = array_values(array_filter(
                $tools,
                fn (ToolInterface $tool): bool => !in_array($tool::class, $this->exclude, true)
                    && ($this->only === [] || in_array($tool::class, $this->only, true))
            ));
        }

        if ($this->with !== []) {
            return array_map(fn (ToolInterface $tool): ToolInterface => isset($this->with[$tool::class]) ? ($this->with[$tool::class]($tool) ?? $tool) : $tool, $tools);
        }

        return $tools;
    }
}
