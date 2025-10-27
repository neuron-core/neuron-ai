<?php

declare(strict_types=1);

namespace NeuronAI\Observability;

use Inspector\Exceptions\InspectorException;

trait Observable
{
    private bool $monitoringInitialized = false;

    /**
     * @var ObserverInterface[]
     */
    private array $observers = [];

    /**
     * @throws InspectorException
     */
    private function initializeMonitoring(): void
    {
        if ($this->monitoringInitialized) {
            return;
        }

        $this->monitoringInitialized = true;

        $this->observe(InspectorObserver::instance());
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

        foreach ($this->observers as $observer) {
            $observer->onEvent($event, $this, $data);
        }
    }

    /**
     * Register an observer to receive events from this component.
     */
    public function observe(ObserverInterface $observer): self
    {
        if ($observer instanceof InspectorObserver) {
            $this->monitoringInitialized = true;
        }

        $this->observers[] = $observer;
        return $this;
    }

    /**
     * Propagate all registered observers to a sub-component.
     */
    protected function propagateObservers(object $component): void
    {
        if (!\method_exists($component, 'observe')) {
            return;
        }

        foreach ($this->observers as $observer) {
            $component->observe($observer);
        }
    }
}
