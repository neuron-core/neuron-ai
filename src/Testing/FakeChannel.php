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

    /** @var array{request: InterruptRequest, address: string}[] */
    public array $suspensions = [];

    /** @var array{state: WorkflowState, address: string}[] */
    public array $completions = [];

    /** @var array{exception: Throwable, address: string}[] */
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

    public function suspended(InterruptRequest $request, string $address): void
    {
        $this->suspensions[] = ['request' => $request, 'address' => $address];
    }

    public function completed(WorkflowState $state, string $address): void
    {
        $this->completions[] = ['state' => $state, 'address' => $address];
    }

    public function failed(Throwable $exception, string $address): void
    {
        $this->failures[] = ['exception' => $exception, 'address' => $address];
    }
}
