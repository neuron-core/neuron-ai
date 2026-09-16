<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Channel;

use NeuronAI\Tests\Workflow\Channel\Stub\RecordingChannel;
use NeuronAI\Workflow\Streaming\ProtocolEvent;
use NeuronAI\Workflow\WorkflowState;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function array_fill;
use function array_map;
use function array_merge;
use function array_sum;
use function base64_decode;
use function count;
use function implode;
use function json_decode;
use function json_encode;
use function mb_check_encoding;
use function range;
use function str_repeat;
use function strtr;

class AbstractChannelTest extends TestCase
{
    protected function state(): WorkflowState
    {
        $state = new WorkflowState();
        $state->setExecutionMetadata('wf-1', 'run-1', 1);

        return $state;
    }

    /**
     * Every event type on the wire, in delivery order.
     *
     * @return string[]
     */
    protected function types(RecordingChannel $channel): array
    {
        return array_map(static fn (ProtocolEvent $event): string => $event->type, array_merge(...$channel->deliveries));
    }

    public function test_delivers_each_event_alone_and_in_order_by_default(): void
    {
        $channel = new RecordingChannel();
        $first = new ProtocolEvent('text-delta', ['delta' => 'a']);
        $second = new ProtocolEvent('text-delta', ['delta' => str_repeat('x', 100_000)]);

        $channel->send($first);
        $channel->send($second);

        // No budget means no fragments, however large the event.
        $this->assertSame([[$first], [$second]], $channel->deliveries);
    }

    public function test_lifecycle_events_carry_the_workflow_id_only(): void
    {
        $channel = new RecordingChannel();

        $channel->interrupted($this->state());
        $channel->completed($this->state(), 'wf-1');
        $channel->failed(new RuntimeException('internal details'), 'wf-1');

        $this->assertSame(
            [
                '{"type":"stream.interrupted","workflowId":"wf-1"}',
                '{"type":"stream.completed","workflowId":"wf-1"}',
                '{"type":"stream.failed","workflowId":"wf-1"}',
            ],
            array_map(static fn (ProtocolEvent $event): string => json_encode($event), array_merge(...$channel->deliveries)),
        );
    }

    public function test_a_batch_leaves_when_full_and_the_lifecycle_flushes_the_rest(): void
    {
        $channel = new RecordingChannel(batchSize: 3);

        foreach (['a', 'b', 'c', 'd'] as $delta) {
            $channel->send(new ProtocolEvent('text-delta', ['delta' => $delta]));
        }
        $this->assertCount(1, $channel->deliveries);

        $channel->completed($this->state(), 'wf-1');

        $this->assertSame([3, 2], array_map(count(...), $channel->deliveries));
        $this->assertSame(
            ['a', 'b', 'c', 'd', 'wf-1'],
            array_map(static fn (ProtocolEvent $event): string => $event->data['delta'] ?? $event->data['workflowId'], array_merge(...$channel->deliveries)),
        );
    }

    public function test_a_batch_leaves_when_the_next_event_would_exceed_the_budget(): void
    {
        $channel = new RecordingChannel(batchSize: 10, budget: 300);

        foreach (range(1, 5) as $ignored) {
            $channel->send(new ProtocolEvent('text-delta', ['delta' => str_repeat('x', 100)]));
        }
        $channel->completed($this->state(), 'wf-1');

        $this->assertSame([2, 2, 2], array_map(count(...), $channel->deliveries));
        foreach ($channel->deliveries as $delivery) {
            $this->assertLessThanOrEqual(300, array_sum(array_map($channel->sizeOf(...), $delivery)));
        }
    }

    public function test_an_event_over_the_budget_travels_as_fragments_that_reassemble_to_the_event(): void
    {
        $channel = new RecordingChannel(budget: 500);
        $data = ['toolCallId' => 'call_1', 'output' => str_repeat('Napoli è bella / ok? ', 100)];

        $channel->send(new ProtocolEvent('tool-output-available', $data));

        $fragments = array_merge(...$channel->deliveries);
        $this->assertGreaterThan(1, count($fragments));
        foreach ($fragments as $index => $fragment) {
            $this->assertSame('stream.fragment', $fragment->type);
            $this->assertSame('tool-output-available', $fragment->data['event']);
            $this->assertSame($index, $fragment->data['index']);
            $this->assertSame(count($fragments), $fragment->data['total']);
            $this->assertMatchesRegularExpression('/^[A-Za-z0-9_=-]+$/', $fragment->data['part']);
            $this->assertLessThanOrEqual(500, $channel->sizeOf($fragment));
        }

        $encoded = base64_decode(strtr(implode('', array_map(
            static fn (ProtocolEvent $fragment): string => $fragment->data['part'],
            $fragments,
        )), '-_', '+/'));
        $this->assertTrue(mb_check_encoding($encoded, 'ASCII'), 'A fragment must decode with atob(), which only carries ASCII.');
        $this->assertSame($data, json_decode($encoded, true));
    }

    public function test_a_fragment_leaves_after_the_pending_batch_and_keeps_the_stream_order(): void
    {
        $channel = new RecordingChannel(batchSize: 10, budget: 500);

        $channel->send(new ProtocolEvent('text-delta', ['delta' => 'before']));
        $channel->send(new ProtocolEvent('tool-output-available', ['output' => str_repeat('x', 2_000)]));
        $channel->send(new ProtocolEvent('text-delta', ['delta' => 'after']));
        $channel->completed($this->state(), 'wf-1');

        $types = $this->types($channel);
        $fragments = count($types) - 3;
        $this->assertGreaterThan(1, $fragments);
        $this->assertSame(
            ['text-delta', ...array_fill(0, $fragments, 'stream.fragment'), 'text-delta', 'stream.completed'],
            $types,
        );
        $this->assertSame(['text-delta'], array_map(static fn (ProtocolEvent $event): string => $event->type, $channel->deliveries[0]));
    }

    public function test_a_failed_delivery_loses_that_batch_only(): void
    {
        $channel = new RecordingChannel(batchSize: 2);
        $channel->send(new ProtocolEvent('text-delta', ['delta' => 'a']));
        $channel->failNextDelivery = true;

        try {
            $channel->send(new ProtocolEvent('text-delta', ['delta' => 'b']));
            $this->fail('The transport failure must propagate.');
        } catch (RuntimeException) {
        }

        $channel->send(new ProtocolEvent('text-delta', ['delta' => 'c']));
        $channel->send(new ProtocolEvent('text-delta', ['delta' => 'd']));

        $this->assertSame(
            [['c', 'd']],
            array_map(static fn (array $delivery): array => array_map(static fn (ProtocolEvent $event): string => $event->data['delta'], $delivery), $channel->deliveries),
        );
    }
}
