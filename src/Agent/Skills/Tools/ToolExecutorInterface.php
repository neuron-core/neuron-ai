<?php

declare(strict_types=1);

namespace NeuronAI\Agent\Skills\Tools;

interface ToolExecutorInterface
{
    /**
     * Whether this executor handles the given tool type.
     */
    public function supports(string $type): bool;

    /**
     * Execute the tool with the given inputs.
     */
    public function execute(ToolDefinition $definition, array $inputs): ToolResult;
}
