<?php

declare(strict_types=1);

namespace NeuronAI\Observability\Events;

use NeuronAI\Observability\ObservabilityEvent;
use Throwable;

/**
 * A channel delivery call failed. The run continues regardless — losing
 * liveness must never lose the run; chat history remains the record the UI
 * reconciles from. Every failure is dispatched: counting, thresholds, and
 * any circuit-breaking policy belong to the listener and the channel
 * implementation, not the engine.
 */
class ChannelError extends ObservabilityEvent
{
    public function __construct(
        public readonly Throwable $exception,
    ) {
    }
}
