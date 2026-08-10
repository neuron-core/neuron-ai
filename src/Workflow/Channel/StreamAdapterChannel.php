<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Channel;

use Closure;
use NeuronAI\Chat\Messages\Stream\Adapters\StreamAdapterInterface;
use NeuronAI\Workflow\Interrupt\InterruptRequest;
use NeuronAI\Workflow\WorkflowState;
use Throwable;

/**
 * Pull/push bridge: renders framework-native stream items through a protocol
 * adapter and emits each output line to a sink, producing byte parity with
 * the pull path (Agent::stream($message, $adapter)) for the same item sequence.
 *
 * The adapter is a stateful transcoder, so the channel OWNS its instance:
 * construct one per channel and never share it with a pull consumer.
 *
 * The protocol start sequence is emitted lazily before the first output, and
 * the end sequence on every terminal — matching the pull path, which emits
 * start/end even for a zero-item run and calls end() on suspension too.
 * Protocol-level suspension/error events are future adapter work; this class
 * never renders the InterruptRequest itself — it is a pure transcoder bridge.
 */
final class StreamAdapterChannel implements ChannelInterface
{
    protected bool $started = false;

    /** @param Closure(string): void $sink Receives each protocol output line. */
    public function __construct(
        protected StreamAdapterInterface $adapter,
        protected Closure $sink,
    ) {
    }

    public function send(object $item): void
    {
        $this->start();

        foreach ($this->adapter->transform($item) as $output) {
            ($this->sink)($output);
        }
    }

    public function suspended(InterruptRequest $request, string $runId): void
    {
        $this->finish();
    }

    public function completed(WorkflowState $state, string $runId): void
    {
        $this->finish();
    }

    public function failed(Throwable $exception, string $runId): void
    {
        $this->finish();
    }

    protected function start(): void
    {
        if ($this->started) {
            return;
        }

        $this->started = true;

        foreach ($this->adapter->start() as $output) {
            ($this->sink)($output);
        }
    }

    protected function finish(): void
    {
        $this->start();

        foreach ($this->adapter->end() as $output) {
            ($this->sink)($output);
        }
    }
}
