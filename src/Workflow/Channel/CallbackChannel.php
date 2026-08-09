<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Channel;

use Closure;
use NeuronAI\Workflow\Interrupt\InterruptRequest;
use NeuronAI\Workflow\WorkflowState;
use Throwable;

/**
 * Universal userland escape hatch: wraps up to four closures, one per
 * channel method. All closures are optional — unset hooks are silent
 * no-ops, so a Redis/Pusher-style transport only needs $onSend.
 */
final class CallbackChannel implements ChannelInterface
{
    /**
     * @param ?Closure(object): void $onSend
     * @param ?Closure(InterruptRequest, string): void $onSuspended
     * @param ?Closure(WorkflowState, string): void $onCompleted
     * @param ?Closure(Throwable, string): void $onFailed
     */
    public function __construct(
        protected ?Closure $onSend = null,
        protected ?Closure $onSuspended = null,
        protected ?Closure $onCompleted = null,
        protected ?Closure $onFailed = null,
    ) {
    }

    public function send(object $item): void
    {
        if ($this->onSend instanceof Closure) {
            ($this->onSend)($item);
        }
    }

    public function suspended(InterruptRequest $request, string $runId): void
    {
        if ($this->onSuspended instanceof Closure) {
            ($this->onSuspended)($request, $runId);
        }
    }

    public function completed(WorkflowState $state, string $runId): void
    {
        if ($this->onCompleted instanceof Closure) {
            ($this->onCompleted)($state, $runId);
        }
    }

    public function failed(Throwable $exception, string $runId): void
    {
        if ($this->onFailed instanceof Closure) {
            ($this->onFailed)($exception, $runId);
        }
    }
}
