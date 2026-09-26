<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Stub;

use Closure;
use DateTimeImmutable;
use NeuronAI\Workflow\Events\StartEvent;
use NeuronAI\Workflow\Events\StopEvent;
use NeuronAI\Workflow\Interrupt\InterruptRequest;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\WorkflowState;

/** Exposes the protected Node helpers so they can be exercised in isolation. */
class ExposedNode extends Node
{
    public function __invoke(StartEvent $event, WorkflowState $state): StopEvent
    {
        return new StopEvent();
    }

    public function callMemoize(string $name, Closure $operation): mixed
    {
        return $this->memoize($name, $operation);
    }

    public function callRecallMemo(string $name): mixed
    {
        return $this->recallMemo($name);
    }

    public function callInterrupt(InterruptRequest $request): ?array
    {
        return $this->interrupt($request);
    }

    public function callInterruptIf(callable|bool $condition, InterruptRequest $request): ?array
    {
        return $this->interruptIf($condition, $request);
    }

    public function callAwaitEvent(string $eventName, ?DateTimeImmutable $expiresAt = null): ?array
    {
        return $this->awaitEvent($eventName, $expiresAt);
    }

    public function callSleepUntil(DateTimeImmutable $wakeAt): ?array
    {
        return $this->sleepUntil($wakeAt);
    }

    public function callEmit(object $event): void
    {
        $this->emit($event);
    }
}
