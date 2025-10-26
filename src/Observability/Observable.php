<?php

declare(strict_types=1);

namespace NeuronAI\Observability;

/**
 * Observable trait using the callback propagation system.
 *
 * This trait provides event emission capabilities to components and
 * automatically initializes AgentMonitoring when configured.
 *
 * Key features:
 * - Callbacks propagate automatically to sub-components
 * - No need to implement SplSubject on every component
 * - Easier to test and mock
 * - Maintains serializability (callbacks must be invokable classes)
 *
 * Usage:
 * ```php
 * class MyComponent
 * {
 *     use Observable;
 *
 *     public function doSomething(): void
 *     {
 *         $this->notify('something-started', $data);
 *         // ... logic ...
 *         $this->notify('something-completed', $result);
 *     }
 * }
 *
 * $component = new MyComponent();
 * $component->addCallback(new MyCallback());
 * ```
 */
trait Observable
{
    /**
     * Flag to track if monitoring has been initialized.
     */
    private bool $monitoringInitialized = false;

    /**
     * @var CallbackInterface[]
     */
    private array $callbacks = [];

    /**
     * Initialize AgentMonitoring if INSPECTOR_INGESTION_KEY is set.
     *
     * This is called lazily on the first notify() call.
     */
    private function initializeMonitoring(): void
    {
        if ($this->monitoringInitialized) {
            return;
        }

        $this->monitoringInitialized = true;

        // Auto-attach AgentMonitoring when INSPECTOR_INGESTION_KEY is set
        if (!empty($_ENV['INSPECTOR_INGESTION_KEY'])) {
            $this->addCallback(NeuronMonitoring::instance());
        }
    }

    /**
     * Notify all callbacks of an event.
     *
     * This method emits events to all registered callbacks and
     * automatically propagates to sub-components.
     *
     * @param string $event The event name (e.g., 'inference-start', 'tool-calling')
     * @param mixed $data Optional event data
     */
    public function notify(string $event, mixed $data = null): void
    {
        // Lazily initialize monitoring on first use
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
    public function addCallback(CallbackInterface $callback): self
    {
        $this->callbacks[] = $callback;
        return $this;
    }

    /**
     * Register multiple callbacks at once.
     *
     * @param CallbackInterface[] $callbacks Array of callbacks to register
     * @return $this
     */
    public function addCallbacks(array $callbacks): self
    {
        foreach ($callbacks as $callback) {
            $this->addCallback($callback);
        }
        return $this;
    }

    /**
     * Remove a specific callback.
     *
     * @param CallbackInterface $callback The callback to remove
     * @return $this
     */
    public function removeCallback(CallbackInterface $callback): self
    {
        $this->callbacks = \array_filter(
            $this->callbacks,
            fn (CallbackInterface $c) => $c !== $callback
        );
        return $this;
    }

    /**
     * Get all registered callbacks.
     *
     * @return CallbackInterface[]
     */
    public function getCallbacks(): array
    {
        return $this->callbacks;
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

    /**
     * Propagate callbacks to multiple components at once.
     *
     * @param object[] $components Array of components to propagate callbacks to
     */
    protected function propagateCallbacksToAll(array $components): void
    {
        foreach ($components as $component) {
            $this->propagateCallbacks($component);
        }
    }

    /**
     * Clear all registered callbacks.
     *
     * Useful for testing or when resetting component state.
     */
    protected function clearCallbacks(): void
    {
        $this->callbacks = [];
    }
}
