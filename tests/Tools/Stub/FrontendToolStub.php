<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools\Stub;

use NeuronAI\Tools\FrontendTool;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\ToolProperty;

class FrontendToolStub extends FrontendTool
{
    /** @param array<string, mixed>|null $inputSchema */
    public function __construct(?array $inputSchema = null)
    {
        parent::__construct('external_lookup', 'Look up information in the external application.', $inputSchema);
    }

    protected function properties(): array
    {
        return [
            new ToolProperty('query', PropertyType::STRING, 'The search query.', true),
        ];
    }
}
