<?php

declare(strict_types=1);

namespace NeuronAI\Observability;

/**
 * Callback interface for event handling in the Neuron framework.
 *
 * Unlike SplObserver which requires subjects to implement SplSubject,
 * this callback-based approach allows for flexible event propagation
 * through component hierarchies without tight coupling.
 *
 * Callbacks must be invokable classes (not closures) to maintain
 * serializability of components.
 *
 * @example
 * ```php
 * class MyCallback implements CallbackInterface
 * {
 *     public function onEvent(string $event, object $source, mixed $data): void
 *     {
 *         match($event) {
 *             'inference-start' => $this->handleInferenceStart($source, $data),
 *             'tool-calling' => $this->handleToolCalling($source, $data),
 *             default => null,
 *         };
 *     }
 * }
 *
 * $agent = Agent::make()
 *     ->addCallback(new MyCallback())
 *     ->chat($message);
 * ```
 */
interface CallbackInterface
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
