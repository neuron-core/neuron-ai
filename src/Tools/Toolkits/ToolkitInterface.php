<?php

declare(strict_types=1);

namespace NeuronAI\Tools\Toolkits;

use NeuronAI\Tools\ToolInterface;

interface ToolkitInterface
{
    public function guidelines(): ?string;

    /**
     * @return ToolInterface[]
     */
    public function tools(): array;

    /**
     * @param  class-string[]  $classes
     */
    public function exclude(array $classes): ToolkitInterface;

    /**
     * @param  class-string[]  $classes
     */
    public function only(array $classes): ToolkitInterface;

    /**
     * @param class-string $class
     */
    public function with(string $class, \Closure $callback): ToolkitInterface;

}
