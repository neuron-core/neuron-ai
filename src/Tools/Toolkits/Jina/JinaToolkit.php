<?php

declare(strict_types=1);

namespace NeuronAI\Tools\Toolkits\Jina;

use NeuronAI\Tools\Tool;
use NeuronAI\Tools\Toolkits\AbstractToolkit;

/**
 * @method static static make(string $key)
 */
class JinaToolkit extends AbstractToolkit
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
            new JinaWebSearch($this->key),
            new JinaUrlReader($this->key),
        ];
    }
}
