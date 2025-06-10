<?php

namespace NeuronAI\Tools\Toolkits;

use NeuronAI\StaticConstructor;
use NeuronAI\Tools\ToolInterface;

abstract class AbstractToolkit implements ToolkitInterface
{
    use StaticConstructor;

    protected array $exclude = [];

    public function guidelines(): ?string
    {
        return null;
    }

    /**
     * @param string[] $classes
     * @return ToolkitInterface
     */
    public function exclude(array $classes): ToolkitInterface
    {
        $this->exclude = $classes;
        return $this;
    }

    /**
     * @return ToolInterface[]
     */
    abstract public function provide(): array;

    public function tools(): array
    {
        return \array_filter($this->provide(), fn (ToolInterface $tool) => !in_array($tool::class, $this->exclude));
    }
}
