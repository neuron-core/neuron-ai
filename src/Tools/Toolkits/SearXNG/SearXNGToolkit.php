<?php

declare(strict_types=1);

namespace NeuronAI\Tools\Toolkits\SearXNG;

use NeuronAI\Tools\Tool;
use NeuronAI\Tools\Toolkits\AbstractToolkit;

/**
 * @method static static make(string $baseUrl)
 */
class SearXNGToolkit extends AbstractToolkit
{
    public function __construct(protected string $baseUrl)
    {
    }

    /**
     * @return array<Tool>
     */
    public function provide(): array
    {
        return [
            new SearXNGSearch($this->baseUrl),
        ];
    }
}
