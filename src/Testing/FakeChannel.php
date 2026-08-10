<?php

declare(strict_types=1);

namespace NeuronAI\Testing;

use NeuronAI\Workflow\Channel\StreamingChannelInterface;
use NeuronAI\Workflow\Interrupt\InterruptRequest;
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

    /** @var array{request: InterruptRequest, runId: string}[] */
    public array $suspensions = [];

    /** @var array{state: WorkflowState, runId: string}[] */
    public array $completions = [];

    /** @var array{exception: Throwable, runId: string}[] */
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

    public function suspended(InterruptRequest $request, string $runId): void
    {
        $this->suspensions[] = ['request' => $request, 'runId' => $runId];
    }

    public function completed(WorkflowState $state, string $runId): void
    {
        $this->completions[] = ['state' => $state, 'runId' => $runId];
    }

    public function failed(Throwable $exception, string $runId): void
    {
        $this->failures[] = ['exception' => $exception, 'runId' => $runId];
    }
}
