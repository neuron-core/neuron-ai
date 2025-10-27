<?php

declare(strict_types=1);

namespace NeuronAI\Observability;

use Inspector\Exceptions\InspectorException;

trait Observable
{
    private bool $monitoringInitialized = false;

    /**
     * @var CallbackInterface[]
     */
    private array $callbacks = [];

    /**
     * @throws InspectorException
     */
    private function initializeMonitoring(): void
    {
        if ($this->monitoringInitialized) {
            return;
        }

        $this->monitoringInitialized = true;

        $this->observe(NeuronMonitoring::instance());
    }

    /**
     * Notify an event.
     *
     * @param string $event The event name (e.g., 'inference-start', 'tool-calling')
     * @param mixed $data Optional event data
     * @throws InspectorException
     */
    public function notify(string $event, mixed $data = null): void
    {
        $this->initializeMonitoring();

        foreach ($this->callbacks as $callback) {
            $callback->onEvent($event, $this, $data);
        }
    }

    /**
     * Register a callback to receive events from this component.
     */
    public function observe(CallbackInterface $callback): self
    {
        $this->callbacks[] = $callback;
        return $this;
    }

    /**
     * Propagate all registered callbacks to a sub-component.
     */
    protected function propagateCallbacks(object $component): void
    {
        if (!\method_exists($component, 'observe')) {
            return;
        }

        foreach ($this->callbacks as $callback) {
            $component->addCallback($callback);
        }
    }
}
