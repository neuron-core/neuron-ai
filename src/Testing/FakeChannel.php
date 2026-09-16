<?php

declare(strict_types=1);

namespace NeuronAI\Testing;

use NeuronAI\StaticConstructor;
use NeuronAI\Workflow\Streaming\Channel\StreamingChannelInterface;
use NeuronAI\Workflow\Streaming\ProtocolEvent;
use NeuronAI\Workflow\WorkflowState;
use PHPUnit\Framework\Assert;
use Throwable;

use function array_filter;
use function array_values;
use function count;

/**
 * Records every channel call for assertions. Set an exception with
 * setThrowOnSend() to exercise the framework's channel failure policy
 * (catch, report, continue).
 */
class FakeChannel implements StreamingChannelInterface
{
    use StaticConstructor;

    /** @var ChannelRecord[] */
    protected array $recorded = [];

    protected ?Throwable $throwOnSend = null;

    public function setThrowOnSend(Throwable $exception): self
    {
        $this->throwOnSend = $exception;
        return $this;
    }

    public function send(ProtocolEvent $event): void
    {
        if ($this->throwOnSend instanceof Throwable) {
            throw $this->throwOnSend;
        }

        $this->recorded[] = new ChannelRecord('send', event: $event);
    }

    public function interrupted(WorkflowState $state): void
    {
        $this->recorded[] = new ChannelRecord('suspended', state: $state);
    }

    public function completed(WorkflowState $state, string $workflowId): void
    {
        $this->recorded[] = new ChannelRecord('completed', state: $state, workflowId: $workflowId);
    }

    public function failed(Throwable $exception, string $workflowId): void
    {
        $this->recorded[] = new ChannelRecord('failed', workflowId: $workflowId, exception: $exception);
    }

    /**
     * @return ChannelRecord[]
     */
    public function getRecorded(): array
    {
        return $this->recorded;
    }

    /**
     * The protocol events delivered through send(), in stream order.
     *
     * @return ProtocolEvent[]
     */
    public function getSent(): array
    {
        $events = [];
        foreach ($this->recorded as $record) {
            if ($record->event instanceof ProtocolEvent) {
                $events[] = $record->event;
            }
        }

        return $events;
    }

    /**
     * @return ChannelRecord[]
     */
    public function getSuspensions(): array
    {
        return $this->records('suspended');
    }

    /**
     * @return ChannelRecord[]
     */
    public function getCompletions(): array
    {
        return $this->records('completed');
    }

    /**
     * @return ChannelRecord[]
     */
    public function getFailures(): array
    {
        return $this->records('failed');
    }

    // ----------------------------------------------------------------
    // PHPUnit Assertions
    // ----------------------------------------------------------------

    /**
     * Assert at least one delivered protocol event matches the callback.
     *
     * @param callable(ProtocolEvent): bool $callback
     */
    public function assertSent(callable $callback): void
    {
        $matched = false;

        foreach ($this->getSent() as $event) {
            if ($callback($event)) {
                $matched = true;
                break;
            }
        }

        Assert::assertTrue($matched, 'No delivered protocol event matched the given assertion callback.');
    }

    public function assertNothingSent(): void
    {
        Assert::assertEmpty(
            $this->getSent(),
            'Expected no protocol events, but ' . count($this->getSent()) . ' were delivered.'
        );
    }

    public function assertSuspended(): void
    {
        Assert::assertNotEmpty($this->getSuspensions(), 'Expected the run segment to be suspended, but it was not.');
    }

    public function assertCompleted(): void
    {
        Assert::assertNotEmpty($this->getCompletions(), 'Expected the run segment to be completed, but it was not.');
    }

    public function assertFailed(): void
    {
        Assert::assertNotEmpty($this->getFailures(), 'Expected the run segment to be failed, but it was not.');
    }

    /**
     * @return ChannelRecord[]
     */
    protected function records(string $method): array
    {
        return array_values(array_filter(
            $this->recorded,
            static fn (ChannelRecord $record): bool => $record->method === $method
        ));
    }
}
