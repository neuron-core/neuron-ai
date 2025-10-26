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
     * Notify all callbacks of an event.
     *
     * This method emits events to all registered callbacks and
     * automatically propagates to sub-components.
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
     *
     * Callbacks are invoked in registration order when events are emitted.
     *
     * @param CallbackInterface $callback The callback to register
     * @return $this
     */
    public function observe(CallbackInterface $callback): self
    {
        $this->callbacks[] = $callback;
        return $this;
    }

    /**
     * Propagate all registered callbacks to a sub-component.
     *
     * This method automatically transfers callbacks from the parent
     * component to a child component, enabling event bubbling through
     * the component hierarchy.
     *
     * If the target component doesn't support callbacks (doesn't have
     * the HasCallbacks trait or addCallback method), this method
     * silently returns without error.
     *
     * @param object $component The component to propagate callbacks to
     */
    protected function propagateCallbacks(object $component): void
    {
        if (!\method_exists($component, 'addCallback')) {
            return;
        }

        foreach ($this->callbacks as $callback) {
            $component->addCallback($callback);
        }
    }
}
