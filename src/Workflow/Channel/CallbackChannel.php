<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Channel;

use Closure;
use NeuronAI\Workflow\Interrupt\InterruptRequest;
use NeuronAI\Workflow\WorkflowState;
use Throwable;

/**
 * Universal userland escape hatch: wraps up to five closures, one per
 * channel method. All closures are optional — unset hooks are silent
 * no-ops, so a Redis/Pusher-style transport only needs $onSend (native
 * chunks) or $onSendLine (adapted protocol lines).
 */
final class CallbackChannel implements StreamingChannelInterface
{
    /**
     * @param ?Closure(object): void $onSend
     * @param ?Closure(string): void $onSendLine
     * @param ?Closure(InterruptRequest, string): void $onSuspended
     * @param ?Closure(WorkflowState, string): void $onCompleted
     * @param ?Closure(Throwable, string): void $onFailed
     */
    public function __construct(
        protected ?Closure $onSend = null,
        protected ?Closure $onSendLine = null,
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

    public function sendLine(string $line): void
    {
        if ($this->onSendLine instanceof Closure) {
            ($this->onSendLine)($line);
        }
    }

    public function suspended(InterruptRequest $request, string $workflowId): void
    {
        if ($this->onSuspended instanceof Closure) {
            ($this->onSuspended)($request, $workflowId);
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
