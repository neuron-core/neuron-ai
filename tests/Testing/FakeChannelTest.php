<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Testing;

use NeuronAI\Testing\ChannelRecord;
use NeuronAI\Testing\FakeChannel;
use NeuronAI\Workflow\Streaming\ProtocolEvent;
use NeuronAI\Workflow\WorkflowState;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function array_map;

class FakeChannelTest extends TestCase
{
    public function test_records_sent_events_in_order(): void
    {
        $channel = new FakeChannel();
        $first = new ProtocolEvent('text-delta', ['delta' => 'Hi']);
        $second = new ProtocolEvent('finish');

        $channel->send($first);
        $channel->send($second);

        $this->assertSame([$first, $second], $channel->getSent());
    }

    public function test_records_segment_lifecycle(): void
    {
        $channel = new FakeChannel();
        $state = new WorkflowState();
        $exception = new RuntimeException('boom');

        $channel->send(new ProtocolEvent('finish'));
        $channel->suspended($state);
        $channel->completed($state, 'wf_1');
        $channel->failed($exception, 'wf_1');

        $this->assertSame(
            ['send', 'suspended', 'completed', 'failed'],
            array_map(fn (ChannelRecord $record): string => $record->method, $channel->getRecorded())
        );
        $this->assertSame($state, $channel->getSuspensions()[0]->state);
        $this->assertSame($state, $channel->getCompletions()[0]->state);
        $this->assertSame('wf_1', $channel->getCompletions()[0]->workflowId);
        $this->assertSame($exception, $channel->getFailures()[0]->exception);
        $this->assertSame('wf_1', $channel->getFailures()[0]->workflowId);
    }

    public function test_throw_on_send_skips_the_recording(): void
    {
        $exception = new RuntimeException('transport down');
        $channel = FakeChannel::make()->setThrowOnSend($exception);

        try {
            $channel->send(new ProtocolEvent('finish'));
            $this->fail('Expected the configured exception.');
        } catch (RuntimeException $caught) {
            $this->assertSame($exception, $caught);
        }

        $channel->assertNothingSent();
    }

    public function test_assertions_pass(): void
    {
        $channel = new FakeChannel();
        $channel->assertNothingSent();

        $channel->send(new ProtocolEvent('text-delta', ['delta' => 'Hi']));
        $channel->suspended(new WorkflowState());
        $channel->completed(new WorkflowState(), 'wf_1');
        $channel->failed(new RuntimeException('boom'), 'wf_1');

        $channel->assertSent(fn (ProtocolEvent $event): bool => $event->type === 'text-delta');
        $channel->assertSuspended();
        $channel->assertCompleted();
        $channel->assertFailed();
        $this->addToAssertionCount(1);
    }

    public function test_assert_sent_fails(): void
    {
        $channel = new FakeChannel();
        $channel->send(new ProtocolEvent('finish'));

        $this->expectException(AssertionFailedError::class);
        $channel->assertSent(fn (ProtocolEvent $event): bool => $event->type === 'text-delta');
    }

    public function test_assert_completed_fails_when_the_segment_did_not_end(): void
    {
        $channel = new FakeChannel();

        $this->expectException(AssertionFailedError::class);
        $channel->assertCompleted();
    }
}
