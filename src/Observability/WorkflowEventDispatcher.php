<?php

declare(strict_types=1);

namespace NeuronAI\Observability;

use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\EventDispatcher\ListenerProviderInterface;
use Psr\EventDispatcher\StoppableEventInterface;

/**
 * Default PSR-14 dispatcher owned by a Workflow instance.
 *
 * Runs the workflow's own listeners (registered via observe()/subscribe()),
 * then forwards the event to an optional external dispatcher (a host
 * framework's PSR-14 implementation) so Neuron events can participate in the
 * wider application.
 */
class WorkflowEventDispatcher implements EventDispatcherInterface
{
    public function __construct(
        protected ListenerProviderInterface $listenerProvider,
        protected ?EventDispatcherInterface $forward = null,
    ) {
    }

    public function dispatch(object $event): object
    {
        foreach ($this->listenerProvider->getListenersForEvent($event) as $listener) {
            if ($event instanceof StoppableEventInterface && $event->isPropagationStopped()) {
                return $event;
            }

            $listener($event);
        }

        if ($this->forward instanceof EventDispatcherInterface) {
            if ($event instanceof StoppableEventInterface && $event->isPropagationStopped()) {
                return $event;
            }

            return $this->forward->dispatch($event);
        }

        return $event;
    }
}
