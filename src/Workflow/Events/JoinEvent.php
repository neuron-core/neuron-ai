<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Events;

/**
 * Emitted when all parallel branches have completed and results are being merged.
 */
class JoinEvent implements Event
{
    /**
     * @param array<string, mixed> $results Map of branch ID to branch result
     */
    public function __construct(
        public readonly array $results
    ) {
    }
}
