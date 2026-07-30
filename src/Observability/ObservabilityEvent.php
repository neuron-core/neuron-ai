<?php

declare(strict_types=1);

namespace NeuronAI\Observability;

use function preg_replace;
use function strrpos;
use function strtolower;
use function substr;

/**
 * Base class for all observability events dispatched through the PSR-14
 * event dispatcher. The event object itself is the payload; the emission
 * context (who emitted it, and from which parallel branch) is stamped on
 * it at dispatch time.
 */
abstract class ObservabilityEvent
{
    /**
     * The component that emitted the event (a Node, the Workflow, ...).
     * Stamped at dispatch time.
     */
    public ?object $source = null;

    /**
     * The parallel-branch identifier when the event originates from a
     * branch, null otherwise. Stamped at dispatch time.
     */
    public ?string $branchId = null;

    /**
     * The string name of the event (e.g. 'inference-start'), derived from
     * the class name unless overridden. Used by legacy observers registered
     * via observe() and by loggers.
     */
    public function name(): string
    {
        $basename = substr(static::class, (int) strrpos(static::class, '\\') + 1);

        return strtolower((string) preg_replace('/(?<!^)[A-Z]/', '-$0', $basename));
    }
}
