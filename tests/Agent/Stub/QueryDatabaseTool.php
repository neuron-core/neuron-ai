<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Agent\Stub;

use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;

class QueryDatabaseTool extends Tool
{
    protected string $name = 'query_database';

    protected ?string $description = 'Execute SQL queries on the database';

    protected function properties(): array
    {
        return [
            new ToolProperty('sql', PropertyType::STRING, 'SQL query', true),
        ];
    }

    public function __invoke(string $sql): string
    {
        return "Results for: {$sql}";
    }
}
