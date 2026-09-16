<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Channel;

use InvalidArgumentException;
use LengthException;
use NeuronAI\Tests\Workflow\Channel\Stub\CountingPayload;
use NeuronAI\Tests\Workflow\Channel\Stub\RecordingChannel;
use NeuronAI\Workflow\Streaming\ProtocolEvent;
use NeuronAI\Workflow\WorkflowState;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function array_column;
use function array_map;
use function array_merge;
use function base64_decode;
use function count;
use function implode;
use function json_decode;
use function json_encode;
use function range;
use function str_repeat;
use function strlen;
use function strtr;

class AbstractChannelTest extends TestCase
{
    /** @return list<array<string, mixed>> */
    protected function events(RecordingChannel $channel): array
    {
        return array_merge(...$channel->deliveries);
    }

    protected function state(): WorkflowState
    {
        $state = new WorkflowState();
        $state->setExecutionMetadata('wf-1', 'run-1', 1);
        return $state;
    }

    public function test_events_have_one_stream_identity_and_increasing_sequences(): void
    {
        $channel = new RecordingChannel();
        $channel->send(new ProtocolEvent('text-delta', ['delta' => 'a', 'type' => 'payload-type']));
        $channel->send(new ProtocolEvent('text-delta', ['delta' => 'b']));
        $channel->completed($this->state(), 'wf-1');
        $events = $this->events($channel);

        $this->assertSame([0, 1, 2], array_column($events, 'sequence'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $events[0]['streamId']);
        $this->assertSame([$events[0]['streamId'], $events[0]['streamId'], $events[0]['streamId']], array_column($events, 'streamId'));
        $this->assertSame('text-delta', $events[0]['type']);
        $this->assertSame(['delta' => 'a', 'type' => 'payload-type'], $events[0]['data']);
    }

    public function test_lifecycle_payloads_expose_only_the_workflow_id_and_reset_the_segment(): void
    {
        $channel = new RecordingChannel();
        $channel->interrupted($this->state());
        $channel->completed($this->state(), 'wf-1');
        $channel->failed(new RuntimeException('internal details'), 'wf-1');
        $events = $this->events($channel);

        $this->assertSame(['stream.interrupted', 'stream.completed', 'stream.failed'], array_column($events, 'type'));
        $this->assertSame([['workflowId' => 'wf-1'], ['workflowId' => 'wf-1'], ['workflowId' => 'wf-1']], array_column($events, 'data'));
        $this->assertSame([0, 0, 0], array_column($events, 'sequence'));
        $this->assertNotSame($events[0]['streamId'], $events[1]['streamId']);
        $this->assertNotSame($events[1]['streamId'], $events[2]['streamId']);
    }

    public function test_batch_count_flushes_data_and_terminal_delivery_is_separate(): void
    {
        $channel = new RecordingChannel(batchSize: 3);
        foreach (['a', 'b', 'c', 'd'] as $delta) {
            $channel->send(new ProtocolEvent('text-delta', ['delta' => $delta]));
        }
        $this->assertCount(1, $channel->deliveries);
        $channel->completed($this->state(), 'wf-1');

        $this->assertSame([3, 1, 1], array_map(count(...), $channel->deliveries));
        $this->assertSame([0, 1, 2, 3, 4], array_column($this->events($channel), 'sequence'));
    }

    public function test_byte_budget_includes_the_batch_wrapper(): void
    {
        $channel = new RecordingChannel(batchSize: 10, budget: 500);
        foreach (range(1, 5) as $ignored) {
            $channel->send(new ProtocolEvent('text-delta', ['delta' => str_repeat('x', 100)]));
        }
        $channel->completed($this->state(), 'wf-1');

        $this->assertSame([2, 2, 1, 1], array_map(count(...), $channel->deliveries));
        foreach ($channel->bytes as $bytes) {
            $this->assertLessThanOrEqual(500, $bytes);
        }
    }

    public function test_fragments_reassemble_unicode_and_have_distinct_message_identities(): void
    {
        $channel = new RecordingChannel(budget: 500);
        $data = ['output' => str_repeat('Napoli è bella / ok? ', 100)];
        $channel->send(new ProtocolEvent('tool-output', $data));
        $channel->send(new ProtocolEvent('tool-output', $data));
        $groups = [];
        foreach ($this->events($channel) as $event) {
            $this->assertSame('stream.fragment', $event['type']);
            $groups[$event['sequence']][] = $event['data'];
        }
        $this->assertCount(2, $groups);
        foreach ($groups as $fragments) {
            $this->assertSame(range(0, count($fragments) - 1), array_column($fragments, 'index'));
            foreach ($fragments as $fragment) {
                $this->assertSame(count($fragments), $fragment['total']);
                $this->assertSame('tool-output', $fragment['event']);
            }
            $decoded = base64_decode(strtr(implode('', array_column($fragments, 'part')), '-_', '+/'), true);
            $this->assertSame($data, json_decode($decoded, true));
        }
        foreach ($channel->bytes as $bytes) {
            $this->assertLessThanOrEqual(500, $bytes);
        }
    }

    public function test_event_limit_is_independent_of_the_delivery_limit(): void
    {
        $channel = new RecordingChannel(batchSize: 10, budget: 5_000, eventBudget: 400);
        $channel->send(new ProtocolEvent('tool-output', ['output' => str_repeat('x', 2_000)]));
        $channel->completed($this->state(), 'wf-1');

        foreach ($this->events($channel) as $event) {
            $this->assertLessThanOrEqual(400, strlen(json_encode($event)));
        }
        foreach ($channel->bytes as $bytes) {
            $this->assertLessThanOrEqual(5_000, $bytes);
        }
    }

    public function test_lifecycle_events_follow_the_same_fragmentation_rules(): void
    {
        $channel = new RecordingChannel(budget: 300);
        $workflowId = str_repeat('w', 1_000);
        $channel->completed($this->state(), $workflowId);
        $fragments = array_column($this->events($channel), 'data');

        $this->assertGreaterThan(1, count($fragments));
        $this->assertSame('stream.completed', $fragments[0]['event']);
        $this->assertSame(['workflowId' => $workflowId], json_decode(base64_decode(strtr(implode('', array_column($fragments, 'part')), '-_', '+/')), true));
        foreach ($channel->bytes as $bytes) {
            $this->assertLessThanOrEqual(300, $bytes);
        }
    }

    public function test_an_impossible_fragment_budget_fails_without_delivering_oversized_bytes(): void
    {
        $channel = new RecordingChannel(budget: 50);
        try {
            $channel->send(new ProtocolEvent('tool-output', ['output' => str_repeat('x', 100)]));
            $this->fail('Expected an impossible-budget error.');
        } catch (LengthException $e) {
            $this->assertStringContainsString('fragment envelope', $e->getMessage());
        }
        $this->assertSame([], $channel->deliveries);
    }

    public function test_invalid_limits_are_rejected(): void
    {
        foreach ([new RecordingChannel(batchSize: 0), new RecordingChannel(budget: 0), new RecordingChannel(eventBudget: -1)] as $channel) {
            try {
                $channel->send(new ProtocolEvent('text-delta'));
                $this->fail('Expected invalid channel limits.');
            } catch (InvalidArgumentException) {
                $this->assertSame([], $channel->deliveries);
            }
        }
    }

    public function test_data_delivery_stops_after_failure_but_terminal_delivery_is_attempted(): void
    {
        $channel = new RecordingChannel(batchSize: 2);
        $channel->send(new ProtocolEvent('text-delta', ['delta' => 'a']));
        $channel->failNextDelivery = true;
        try {
            $channel->send(new ProtocolEvent('text-delta', ['delta' => 'b']));
            $this->fail('Expected transport failure.');
        } catch (RuntimeException) {
        }
        $channel->send(new ProtocolEvent('text-delta', ['delta' => 'c']));
        $channel->send(new ProtocolEvent('text-delta', ['delta' => 'd']));
        $this->assertSame(1, $channel->attempts);
        $channel->completed($this->state(), 'wf-1');

        $this->assertSame(2, $channel->attempts);
        $this->assertSame(['stream.completed'], array_column($this->events($channel), 'type'));
        $this->assertSame(2, $this->events($channel)[0]['sequence']);
        $channel->send(new ProtocolEvent('text-delta', ['delta' => 'new segment']));
        $channel->completed($this->state(), 'wf-2');
        $this->assertSame(0, $this->events($channel)[1]['sequence']);
        $this->assertNotSame($this->events($channel)[0]['streamId'], $this->events($channel)[1]['streamId']);
    }

    public function test_terminal_notification_survives_failure_flushing_pending_data(): void
    {
        $channel = new RecordingChannel(batchSize: 10);
        $channel->send(new ProtocolEvent('text-delta'));
        $channel->failNextDelivery = true;
        try {
            $channel->completed($this->state(), 'wf-1');
            $this->fail('The original delivery failure must be reported.');
        } catch (RuntimeException $e) {
            $this->assertSame('transport down', $e->getMessage());
        }
        $this->assertSame(2, $channel->attempts);
        $this->assertSame(['stream.completed'], array_column($this->events($channel), 'type'));
    }

    public function test_an_unavailable_transport_gets_no_terminal_retries(): void
    {
        $channel = new RecordingChannel(batchSize: 10);
        $channel->send(new ProtocolEvent('text-delta'));
        $channel->alwaysFail = true;
        try {
            $channel->failed(new RuntimeException('workflow error'), 'wf-1');
            $this->fail('Expected transport failure.');
        } catch (RuntimeException) {
        }
        $this->assertSame(2, $channel->attempts);
        $channel->alwaysFail = false;
        $channel->completed($this->state(), 'wf-2');
        $this->assertSame(0, $this->events($channel)[0]['sequence']);
    }

    public function test_payload_serialization_runs_once_for_plain_and_fragmented_events(): void
    {
        foreach ([null, 500] as $budget) {
            $payload = new CountingPayload(str_repeat('x', 2_000));
            $channel = new RecordingChannel(budget: $budget);
            $channel->send(new ProtocolEvent('tool-output', ['output' => $payload]));
            $this->assertSame(1, $payload->calls);
        }
    }
}
