<?php

declare(strict_types=1);

namespace NeuronAI\Workflow;

use NeuronAI\Observability\EventDispatcher;
use NeuronAI\Observability\ListenerRegistry;
use NeuronAI\Observability\ObservabilityEvent;
use NeuronAI\Observability\ObserverAdapter;
use NeuronAI\Observability\ObserverInterface;
use Psr\EventDispatcher\EventDispatcherInterface;

trait HandleDispatcher
{
    protected ?EventDispatcherInterface $dispatcher = null;

    protected ?EventDispatcherInterface $externalDispatcher = null;

    /**
     * @deprecated Use subscribe() with a PSR-14 listener instead. Will be
     *             removed in the next major version.
     */
    public function observe(ObserverInterface $observer): static
    {
        return $this->subscribe(ObservabilityEvent::class, new ObserverAdapter($observer));
    }

    /**
     * Matching is instanceof-based: subscribing to ObservabilityEvent
     * receives every event.
     *
     * @param class-string $eventClass
     */
    public function subscribe(string $eventClass, callable $listener): static
    {
        $this->listeners = clone $this->listenerRegistry();
        $this->listeners->listen($eventClass, $listener);
        $this->dispatcher = null;
        return $this;
    }

    /**
     * Forward this workflow's events to an external PSR-14 dispatcher.
     * Listeners registered via observe()/subscribe() run first.
     */
    public function setEventDispatcher(EventDispatcherInterface $dispatcher): static
    {
        $this->externalDispatcher = $dispatcher;
        // Rebuild the resolved dispatcher with the new forward target.
        $this->dispatcher = null;
        return $this;
    }

    public function getEventDispatcher(): EventDispatcherInterface
    {
        return $this->dispatcher ??= new EventDispatcher(
            $this->listenerRegistry(),
            $this->externalDispatcher,
        );
    }

    protected function listenerRegistry(): ListenerRegistry
    {
        return $this->listeners ??= new ListenerRegistry();
    }
}
