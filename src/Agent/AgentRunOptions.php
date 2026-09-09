<?php

declare(strict_types=1);

namespace NeuronAI\Agent;

/**
 * Choices recorded when a run starts and carried through inference and tool loops.
 * Framework nodes preserve them; applications may edit them explicitly.
 */
class AgentRunOptions
{
    public function __construct(
        public bool $stream = false,
        public ?string $outputClass = null,
        public int $maxRetries = 1,
        public bool $recallMemory = true,
        public bool $rememberMemory = true,
    ) {
    }
}
