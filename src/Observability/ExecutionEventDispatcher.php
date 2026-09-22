<?php

declare(strict_types=1);

namespace NeuronAI\Observability;

use NeuronAI\Workflow\ExecutionContext;
use NeuronAI\Workflow\WorkflowExecution;
use Psr\EventDispatcher\EventDispatcherInterface;

/** Adds execution metadata to lifecycle and node events. @internal */
final class ExecutionEventDispatcher implements EventDispatcherInterface
{
    public function __construct(protected EventDispatcherInterface $dispatcher, protected ExecutionContext $context)
    {
    }

    public function dispatch(object $event): object
    {
        if ($event instanceof ObservabilityEvent) {
            $event->execution = $this->context;
            if ($event->source instanceof WorkflowExecution) {
                $event->source = $event->source->definition;
            }
        }
        return $this->dispatcher->dispatch($event);
    }
}
