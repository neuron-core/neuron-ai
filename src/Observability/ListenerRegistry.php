<?php

declare(strict_types=1);

namespace NeuronAI\Observability;

use Psr\EventDispatcher\ListenerProviderInterface;

/**
 * Class-keyed listener provider. Listeners are registered against an event
 * class (or a parent class such as ObservabilityEvent to receive every event)
 * and matched with instanceof semantics.
 */
class ListenerRegistry implements ListenerProviderInterface
{
    /**
     * @var array<class-string, callable[]>
     */
    protected array $listeners = [];

    /**
     * @param class-string $eventClass
     */
    public function listen(string $eventClass, callable $listener): void
    {
        $this->listeners[$eventClass][] = $listener;
    }

    /**
     * @return iterable<callable>
     */
    public function getListenersForEvent(object $event): iterable
    {
        foreach ($this->listeners as $eventClass => $listeners) {
            if ($event instanceof $eventClass) {
                yield from $listeners;
            }
        }
    }
}
