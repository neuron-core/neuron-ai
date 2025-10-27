<?php

declare(strict_types=1);

namespace NeuronAI\Observability;

interface ObserverInterface
{
    /**
     * Handle an event from a component.
     *
     * @param string $event The event name (e.g., 'inference-start', 'tool-called')
     * @param object $source The component that emitted the event
     * @param mixed $data Additional event data (optional)
     */
    public function onEvent(string $event, object $source, mixed $data = null): void;
}
