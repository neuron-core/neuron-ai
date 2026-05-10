<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Agent\Tools;

use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use RuntimeException;
use stdClass;

class CrashSearchTool extends Tool
{
    public function __construct(
        protected object $counter = new stdClass(),
    ) {
        $this->counter->count = 0;
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
        $this->counter->count++;
        if ($this->counter->count === 1) {
            throw new RuntimeException('Simulated crash during tool execution');
        }
        return 'Results for: PHP frameworks';
    }

    public function getCallCount(): int
    {
        return $this->counter->count;
    }
}
