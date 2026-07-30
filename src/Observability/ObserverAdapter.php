<?php

declare(strict_types=1);

namespace NeuronAI\Observability;

/**
 * Adapts a legacy ObserverInterface to a PSR-14 listener. Registered against
 * ObservabilityEvent so the observer keeps receiving every event, converted back
 * to the (name, source, data, branchId) signature it expects.
 *
 * @deprecated Exists only to support the deprecated observe()/ObserverInterface
 *             path and will be removed with it in the next major version.
 */
class ObserverAdapter
{
    public function __construct(protected ObserverInterface $observer)
    {
    }

    public function __invoke(ObservabilityEvent $event): void
    {
        $this->observer->onEvent(
            $event->name(),
            $event->source ?? $event,
            $event,
            $event->branchId ?? '__main__',
        );
    }
}
