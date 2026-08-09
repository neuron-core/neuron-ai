<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Channel;

use NeuronAI\Workflow\Interrupt\InterruptRequest;
use NeuronAI\Workflow\WorkflowState;
use Throwable;

/**
 * Inert default channel: delivery goes nowhere. Workflow::fireChannel()
 * fast-paths on this class so an unwired workflow pays one property check
 * per yielded item — today's behavior, unchanged.
 */
final class NullChannel implements ChannelInterface
{
    public function send(object $item): void
    {
    }

    public function suspended(InterruptRequest $request, string $runId): void
    {
    }

    public function completed(WorkflowState $state, string $runId): void
    {
    }

    public function failed(Throwable $exception, string $runId): void
    {
    }
}
