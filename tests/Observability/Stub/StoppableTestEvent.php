<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Observability\Stub;

use NeuronAI\Observability\ObservabilityEvent;
use Psr\EventDispatcher\StoppableEventInterface;

class StoppableTestEvent extends ObservabilityEvent implements StoppableEventInterface
{
    protected bool $stopped = false;

    public function stopPropagation(): void
    {
        $this->stopped = true;
    }

    public function isPropagationStopped(): bool
    {
        return $this->stopped;
    }
}
