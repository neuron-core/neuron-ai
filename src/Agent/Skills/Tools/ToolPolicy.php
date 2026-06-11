<?php

declare(strict_types=1);

namespace NeuronAI\Agent\Skills\Tools;

/**
 * Execution policy for a tool definition.
 */
class ToolPolicy
{
    public function __construct(
        public readonly bool $idempotent = false,
        public readonly bool $sideEffect = true,
        public readonly int $maxCalls = 0,
        public readonly bool $retryOnFailure = false,
    ) {
    }
}
