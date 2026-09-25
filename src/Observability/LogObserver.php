<?php

declare(strict_types=1);

namespace NeuronAI\Observability;

use function is_array;
use function is_object;

/**
 * Credits: https://github.com/sixty-nine
 *
 * @deprecated Use LogListener instead, registered via
 *             Workflow::subscribe(ObservabilityEvent::class, new LogListener($logger)).
 *             Override LogListener::context() to change what is logged.
 *             Will be removed in the next major version.
 */
class LogObserver extends LogListener implements ObserverInterface
{
    public function onEvent(string $event, object $source, mixed $data = null, ?string $branchId = null): void
    {
        $this->logger->log($this->level, $event, $this->serializeData($data));
    }

    /**
     * @return array<mixed>
     */
    protected function serializeData(mixed $data): array
    {
        if ($data instanceof ObservabilityEvent) {
            return $this->context($data);
        }

        if (is_array($data)) {
            return $data;
        }

        return $data === null || is_object($data) ? [] : ['data' => $data];
    }
}
