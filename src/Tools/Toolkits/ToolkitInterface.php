<?php

namespace NeuronAI\Tools\Toolkits;

use NeuronAI\Tools\ToolInterface;

interface ToolkitInterface
{
    public function guidelines(): ?string;

    /**
     * @return ToolInterface[]
     */
    public function tools(): array;
}
