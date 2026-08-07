<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Stubs\Tools;

use NeuronAI\Tools\Tool;

/**
 * Concrete no-op Tool for tests that need a registry/schema-side tool
 * (setTools payloads, property mapping) without declaring a dedicated class.
 * Conversation-side entries use ToolCall instead (ADR 0010).
 */
class ToolStub extends Tool
{
    public function __construct(string $name, ?string $description = null)
    {
        $this->name = $name;
        $this->description = $description;
    }

    public function __invoke(mixed ...$arguments): mixed
    {
        return null;
    }
}
