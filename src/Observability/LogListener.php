<?php

declare(strict_types=1);

namespace NeuronAI\Observability;

use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

/**
 * PSR-3 logging as a PSR-14 listener. Register it against ObservabilityEvent to
 * log every event of a workflow:
 *
 *     $workflow->subscribe(ObservabilityEvent::class, new LogListener($logger));
 *
 * Each record is the event's name with the event's own data as context.
 *
 * Credits: https://github.com/sixty-nine
 */
class LogListener
{
    public function __construct(
        protected readonly LoggerInterface $logger,
        protected string $level = LogLevel::INFO
    ) {
    }

    public function __invoke(ObservabilityEvent $event): void
    {
        $this->logger->log($this->level, $event->name(), $this->context($event));
    }

    /**
     * Override this method in child classes to redact or enrich what is logged.
     *
     * @return array<string, mixed>
     */
    protected function context(ObservabilityEvent $event): array
    {
        return $event->toArray();
    }
}
