<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Streaming\Channel;

use Closure;
use NeuronAI\Workflow\Streaming\ProtocolEvent;
use NeuronAI\Workflow\WorkflowState;
use Throwable;

/**
 * Universal userland escape hatch: wraps up to four closures, one per
 * channel method. All closures are optional — unset hooks are silent
 * no-ops, so a Redis/Pusher-style transport only needs $onSend.
 */
final class CallbackChannel implements StreamingChannelInterface
{
    /**
     * @param ?Closure(ProtocolEvent): void $onSend
     * @param ?Closure(WorkflowState): void $onInterrupted
     * @param ?Closure(WorkflowState, string): void $onCompleted
     * @param ?Closure(Throwable, string): void $onFailed
     */
    public function __construct(
        protected ?Closure $onSend = null,
        protected ?Closure $onInterrupted = null,
        protected ?Closure $onCompleted = null,
        protected ?Closure $onFailed = null,
    ) {
    }

    public function send(ProtocolEvent $event): void
    {
        if ($this->onSend instanceof Closure) {
            ($this->onSend)($event);
        }
    }

    public function interrupted(WorkflowState $state): void
    {
        if ($this->onInterrupted instanceof Closure) {
            ($this->onInterrupted)($state);
        }
    }

    public function completed(WorkflowState $state, string $workflowId): void
    {
        if ($this->onCompleted instanceof Closure) {
            ($this->onCompleted)($state, $workflowId);
        }
    }

    public function failed(Throwable $exception, string $workflowId): void
    {
        if ($this->onFailed instanceof Closure) {
            ($this->onFailed)($exception, $workflowId);
        }
    }
}
