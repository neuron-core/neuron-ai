<?php

declare(strict_types=1);

namespace NeuronAI\Testing;

use NeuronAI\Workflow\Channel\StreamingChannelInterface;
use NeuronAI\Workflow\WorkflowState;
use Throwable;

/**
 * Records every channel call for assertions. Set $throwOnSend to exercise
 * the framework's channel failure policy (catch, report, mute-after-N).
 */
final class FakeChannel implements StreamingChannelInterface
{
    /** @var object[] */
    public array $sent = [];

    /** @var string[] */
    public array $lines = [];

    /** @var WorkflowState[] */
    public array $suspendedStates = [];

    /** @var array{state: WorkflowState, workflowId: string}[] */
    public array $completions = [];

    /** @var array{exception: Throwable, workflowId: string}[] */
    public array $failures = [];

    public ?Throwable $throwOnSend = null;

    public function send(object $item): void
    {
        if ($this->throwOnSend instanceof Throwable) {
            throw $this->throwOnSend;
        }

        $this->sent[] = $item;
    }

    public function sendLine(string $line): void
    {
        $this->lines[] = $line;
    }

    public function suspended(WorkflowState $state): void
    {
        $this->suspendedStates[] = $state;
    }

    public function completed(WorkflowState $state, string $workflowId): void
    {
        $this->completions[] = ['state' => $state, 'workflowId' => $workflowId];
    }

    public function failed(Throwable $exception, string $workflowId): void
    {
        $this->failures[] = ['exception' => $exception, 'workflowId' => $workflowId];
    }
}
