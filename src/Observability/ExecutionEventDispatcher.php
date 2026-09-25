<?php

declare(strict_types=1);

namespace NeuronAI\Observability;

use NeuronAI\Observability\Events\AgentError;
use NeuronAI\Workflow\ExecutionContext;
use Psr\EventDispatcher\EventDispatcherInterface;
use Throwable;

/**
 * The dispatcher of one execution segment. Its events carry the segment's
 * execution context, and an event without a source comes from the workflow.
 *
 * @internal
 */
final class ExecutionEventDispatcher implements EventDispatcherInterface
{
    public function __construct(
        protected EventDispatcherInterface $dispatcher,
        protected ExecutionContext $context,
        protected object $source,
    ) {
    }

    public function dispatch(object $event): object
    {
        if ($event instanceof ObservabilityEvent) {
            $event->execution = $this->context;
            $event->source ??= $this->source;
        }
        return $this->dispatcher->dispatch($event);
    }

    /**
     * Dispatch what the execution reports about itself. Monitoring never
     * changes the execution: a failing listener is reported as an AgentError,
     * and a failure to report it is dropped.
     */
    public function report(ObservabilityEvent $event): void
    {
        try {
            $this->dispatch($event);
        } catch (Throwable $e) {
            if (!$event instanceof AgentError) {
                $error = new AgentError($e, false);
                $error->source = $event->source;
                $error->branchId = $event->branchId;
                $this->report($error);
            }
        }
    }
}
