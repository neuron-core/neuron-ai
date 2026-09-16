<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Streaming\Channel;

use Closure;
use NeuronAI\Workflow\Streaming\ProtocolEvent;
use NeuronAI\Workflow\WorkflowState;
use Throwable;

/**
 * Direct lifecycle callbacks, without the AbstractChannel wire envelope.
 * Unset callbacks are no-ops: onSend alone does not receive lifecycle events.
 * Extend AbstractChannel to implement a transport with the shared wire contract.
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
