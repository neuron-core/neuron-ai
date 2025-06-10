<?php

namespace NeuronAI\Tools\Toolkits\Riza;

use NeuronAI\Tools\Tool;
use NeuronAI\Tools\Toolkits\AbstractToolkit;

class RizaToolkit extends AbstractToolkit
{
    public function __construct(protected string $key)
    {
    }

    /**
     * @return array<Tool>
     */
    public function provide(): array
    {
        return [
            new RizaCodeInterpreter($this->key),
            new RizaFunctionExecutor($this->key),
        ];
    }
}
