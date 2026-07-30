<?php

declare(strict_types=1);

namespace NeuronAI\Observability;

/**
 * Credits: https://github.com/sixty-nine
 *
 * @deprecated Use LogListener instead, registered via
 *             Workflow::subscribe(ObservabilityEvent::class, new LogListener($logger)).
 *             Serialization overrides keep working — LogListener carries the same
 *             protected serialize* methods. Will be removed in the next major version.
 */
class LogObserver extends LogListener implements ObserverInterface
{
    public function onEvent(string $event, object $source, mixed $data = null, ?string $branchId = null): void
    {
        $this->logger->log($this->level, $event, $this->serializeData($data));
    }
}
