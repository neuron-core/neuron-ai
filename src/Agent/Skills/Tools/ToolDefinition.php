<?php

declare(strict_types=1);

namespace NeuronAI\Agent\Skills\Tools;

/**
 * Immutable value object representing a parsed tool definition from the ## Tools section.
 */
class ToolDefinition
{
    /**
     * @param string $name Tool identifier (kebab-case)
     * @param string $type Executor type: shell, http, php, queue, mcp
     * @param string $description Human-readable description for the LLM
     * @param array<string, string|array> $inputSchema Typed input parameters
     * @param array<string, mixed> $execution Type-specific execution configuration
     * @param array<string, string>|null $outputSchema Expected output structure
     * @param ToolPolicy|null $policy Execution constraints
     */
    public function __construct(
        public readonly string $name,
        public readonly string $type,
        public readonly string $description,
        public readonly array $inputSchema,
        public readonly array $execution,
        public readonly ?array $outputSchema = null,
        public readonly ?ToolPolicy $policy = null,
    ) {
    }
}
