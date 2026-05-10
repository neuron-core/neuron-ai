<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Agent\Tools;

use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;

class SearchTool extends Tool
{
    public function __construct()
    {
        parent::__construct(
            'search',
            'Search the web',
        );
    }

    protected function properties(): array
    {
        return [
            new ToolProperty('query', PropertyType::STRING, 'Search query', true),
        ];
    }

    public function __invoke(string $query): string
    {
        return "Results for: {$query}";
    }
}
