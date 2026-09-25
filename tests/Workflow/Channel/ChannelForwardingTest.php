<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Channel;

use Generator;
use LogicException;
use NeuronAI\Agent\Interrupt\ApprovalRequest;
use NeuronAI\Testing\FakeChannel;
use NeuronAI\Tests\Workflow\Channel\Stub\ChunkAdapter;
use NeuronAI\Tests\Workflow\Channel\Stub\ChunkStreamingNode;
use NeuronAI\Tests\Workflow\Channel\Stub\PostStreamNode;
use NeuronAI\Tests\Workflow\Channel\Stub\PreStreamNode;
use NeuronAI\Tests\Workflow\Channel\Stub\SharedRequestInterruptNode;
use NeuronAI\Tests\Workflow\Channel\Stub\ThrowingNode;
use NeuronAI\Tests\Workflow\Channel\Stub\TwoStageInterruptNode;
use NeuronAI\Tests\Workflow\Executor\Stub\ChunkEvent;
use NeuronAI\Tests\Workflow\Stub\InterruptableNode;
use NeuronAI\Tests\Workflow\Stub\NodeOne;
use NeuronAI\Tests\Workflow\Stub\NodeThree;
use NeuronAI\Tests\Workflow\Stub\NodeTwo;
use NeuronAI\Tests\Workflow\Stub\WaitForEventNode;
use NeuronAI\Workflow\Events\InterruptEvent;
use NeuronAI\Workflow\Observability\ChannelError;
use NeuronAI\Workflow\Observability\WorkflowError;
use NeuronAI\Workflow\Streaming\Adapter\StreamAdapterInterface;
use NeuronAI\Workflow\Streaming\Channel\CallbackChannel;
use NeuronAI\Workflow\Streaming\Channel\StreamingChannelInterface;
use NeuronAI\Workflow\Streaming\ProtocolEvent;
use NeuronAI\Workflow\Workflow;
use NeuronAI\Workflow\WorkflowState;
use NeuronAI\Workflow\WorkflowStatus;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

use function array_map;
use function count;
use function iterator_to_array;

class ChannelForwardingTest extends TestCase
{
    // ------------------------------------------------------------------
    // Phase 1 — the seam
    // ------------------------------------------------------------------

    public function test_wired_channel_receives_every_yielded_item_in_order_via_run(): void
    {
        $channel = new FakeChannel();
        $workflow = Workflow::make('test-workflow')
            ->addNodes([new ChunkStreamingNode(3)])
            ->setStreamAdapter(fn (): ChunkAdapter => new ChunkAdapter())
            ->setChannel(fn (): StreamingChannelInterface => $channel);

        $workflow->run();

        $payloads = array_map(
            static fn (ProtocolEvent $event): string => $event->data['payload'],
            $channel->getSent(),
        );

        $this->assertSame(['chunk-1', 'chunk-2', 'chunk-3'], $payloads);
        $this->assertCount(1, $channel->getCompletions());
        $this->assertSame($workflow->getWorkflowId(), $channel->getCompletions()[0]->workflowId);
        $this->assertSame([], $channel->getSuspensions());
        $this->assertSame([], $channel->getFailures());
    }

    public function test_output_changes_during_streaming_apply_to_the_next_execution(): void
    {
        $firstChannel = new FakeChannel();
        $nextChannel = new FakeChannel();
        $workflow = Workflow::make('test-workflow')
            ->addNode(new ChunkStreamingNode(2))
            ->setStreamAdapter(fn (): ChunkAdapter => new ChunkAdapter())
            ->setChannel(fn (): StreamingChannelInterface => $firstChannel);

        $stream = $workflow->events();
        $stream->rewind();

        $workflow->setChannel(fn (): StreamingChannelInterface => $nextChannel)->setStreamAdapter(null);

        $events = iterator_to_array($stream, false);
        $this->assertEquals([
            new ProtocolEvent('chunk', ['payload' => 'chunk-1']),
            new ProtocolEvent('chunk', ['payload' => 'chunk-2']),
        ], $events);
        $this->assertSame($events, $firstChannel->getSent());
        $this->assertCount(1, $firstChannel->getCompletions());
        $this->assertSame([], $nextChannel->getRecorded());

        $nextEvents = iterator_to_array($workflow->events(), false);
        $this->assertCount(2, $nextEvents);
        $this->assertContainsOnlyInstancesOf(ChunkEvent::class, $nextEvents);
        $this->assertSame([], $nextChannel->getSent());
        $this->assertCount(1, $nextChannel->getCompletions());
        $this->assertSame($events, $firstChannel->getSent());
        $this->assertCount(1, $firstChannel->getCompletions());
    }

    public function test_run_delivers_to_a_channel_and_returns_the_final_state(): void
    {
        $channel = new FakeChannel();
        $workflow = Workflow::make('test-workflow')
            ->addNodes([new ChunkStreamingNode(2)])
            ->setStreamAdapter(fn (): ChunkAdapter => new ChunkAdapter())
            ->setChannel(fn (): StreamingChannelInterface => $channel);

        $state = $workflow->run();

        $this->assertInstanceOf(WorkflowState::class, $state);
        $this->assertSame(WorkflowStatus::Completed, $state->getStatus());
        $this->assertSame(['chunk-1', 'chunk-2'], array_map(
            static fn (ProtocolEvent $event): string => $event->data['payload'],
            $channel->getSent(),
        ));
        $this->assertCount(1, $channel->getCompletions());
        $this->assertEquals($state, $channel->getCompletions()[0]->state);
    }

    public function test_events_remain_lazy_with_every_channel_configuration(): void
    {
        foreach ([[false, false], [true, false], [false, true], [true, true]] as [$adapter, $channel]) {
            $workflow = Workflow::make('test-workflow')
                ->addNodes([new NodeOne(), new NodeTwo(), new NodeThree()])
                ->setStreamAdapter(fn (): ?StreamAdapterInterface => $adapter ? new ChunkAdapter() : null)
                ->setChannel(fn (): ?StreamingChannelInterface => $channel ? new FakeChannel() : null);

            $stream = $workflow->events();

            $this->assertInstanceOf(Generator::class, $stream);
            $this->assertNull($workflow->inspect());
            iterator_to_array($stream);
            $this->assertTrue($stream->getReturn()->get('node_one_executed'));
        }
    }

    public function test_signal_eagerly_delivers_the_continuation_to_the_channel(): void
    {
        $channel = new FakeChannel();
        $workflow = Workflow::make('test-workflow')
            ->addNodes([new PreStreamNode(), new WaitForEventNode(), new PostStreamNode()])
            ->setStreamAdapter(fn (): ChunkAdapter => new ChunkAdapter())
            ->setChannel(fn (): StreamingChannelInterface => $channel);

        $this->assertTrue($workflow->run()->isInterrupted());

        $state = $workflow->run(\NeuronAI\Workflow\Executor\ExecutionRequest::signal('user.signup', ['user' => 42]));

        $this->assertSame(WorkflowStatus::Completed, $state->getStatus());
        $this->assertSame(['user' => 42], $state->get('received_payload'));
        $this->assertSame(['pre', 'post'], array_map(
            static fn (ProtocolEvent $event): string => $event->data['payload'],
            $channel->getSent(),
        ));
        $this->assertCount(1, $channel->getSuspensions());
        $this->assertCount(1, $channel->getCompletions());
    }

    public function test_channel_send_failures_never_fail_the_run_and_every_failure_is_dispatched(): void
    {
        $failure = new RuntimeException('transport down');
        $channel = FakeChannel::make()->setThrowOnSend($failure);

        $errors = [];
        $workflow = Workflow::make('test-workflow')
            ->addNodes([new ChunkStreamingNode(5)])
            ->setStreamAdapter(fn (): ChunkAdapter => new ChunkAdapter())
            ->setChannel(fn (): StreamingChannelInterface => $channel);
        $workflow->subscribe(ChannelError::class, function (ChannelError $error) use (&$errors): void {
            $errors[] = $error;
        });

        $state = $workflow->run();

        $this->assertFalse($state->isInterrupted());
        // Every delivery is attempted and every failure dispatched — there is
        // no framework mute; counting/thresholds are the listener's policy,
        // circuit-breaking the channel implementation's.
        $this->assertCount(5, $errors);
        foreach ($errors as $error) {
            $this->assertSame($failure, $error->exception);
        }
        // The terminal is still delivered — failures never lose the run.
        $this->assertCount(1, $channel->getCompletions());
    }

    public function test_channel_without_adapter_receives_only_the_lifecycle(): void
    {
        $channel = new FakeChannel();
        $workflow = Workflow::make('test-workflow')
            ->addNodes([new ChunkStreamingNode(3)])
            ->setChannel(fn (): StreamingChannelInterface => $channel);

        $pulled = [];
        foreach ($workflow->events() as $item) {
            $pulled[] = $item;
        }

        // Native output stays on the pull path: a channel speaks the adapter's protocol.
        $this->assertCount(3, $pulled);
        $this->assertContainsOnlyInstancesOf(ChunkEvent::class, $pulled);
        $this->assertSame([], $channel->getSent());
        $this->assertCount(1, $channel->getCompletions());
    }

    // ------------------------------------------------------------------
    // Phase 2 — terminals
    // ------------------------------------------------------------------

    public function test_suspension_delivers_one_state_with_the_active_request(): void
    {
        $request = new ApprovalRequest('needs a human');
        $channel = new FakeChannel();
        $workflow = Workflow::make('test-workflow')
            ->addNodes([new NodeOne(), new SharedRequestInterruptNode($request)])
            ->setChannel(fn (): StreamingChannelInterface => $channel);

        $pulled = [];
        $generator = $workflow->events();
        foreach ($generator as $event) {
            $pulled[] = $event;
        }
        $state = $generator->getReturn();

        $this->assertTrue($state->isInterrupted());

        // The channel receives one state-level snapshot with the ID-bound request.
        $this->assertCount(1, $channel->getSuspensions());
        $delivered = $channel->getSuspensions()[0]->state;
        $this->assertSame($workflow->getWorkflowId(), $delivered->getWorkflowId());
        $this->assertSame('needs a human', $delivered->getInterruptRequest()->getMessage());
        $this->assertSame(1, $delivered->getInterruptRequest()->getId());

        // …and never the InterruptEvent — nor does a suspended segment complete.
        $this->assertSame([], $channel->getSent());
        $this->assertSame([], $channel->getCompletions());

        // Pull consumers still receive the InterruptEvent terminal, unchanged.
        $this->assertInstanceOf(InterruptEvent::class, $pulled[count($pulled) - 1]);
    }

    public function test_re_interruption_delivers_a_new_state_snapshot(): void
    {
        $channel = new FakeChannel();
        $workflow = Workflow::make('test-workflow')
            ->addNodes([new NodeOne(), new TwoStageInterruptNode(), new NodeThree()])
            ->setChannel(fn (): StreamingChannelInterface => $channel);

        $workflow->run();
        // An incomplete payload interrupts again with a new active request.
        $workflow->run(\NeuronAI\Workflow\Executor\ExecutionRequest::resume(['partial' => true]));

        $this->assertCount(2, $channel->getSuspensions());
        $this->assertInstanceOf(ApprovalRequest::class, $channel->getSuspensions()[0]->state->getInterruptRequest());
        $this->assertInstanceOf(ApprovalRequest::class, $channel->getSuspensions()[1]->state->getInterruptRequest());
        $this->assertSame('stage one', $channel->getSuspensions()[0]->state->getInterruptRequest()->getMessage());
        $this->assertSame('stage two', $channel->getSuspensions()[1]->state->getInterruptRequest()->getMessage());
        $this->assertSame(
            $channel->getSuspensions()[0]->state->getWorkflowId(),
            $channel->getSuspensions()[1]->state->getWorkflowId(),
        );
        $this->assertCount(0, $channel->getCompletions());

        $state = $workflow->run(\NeuronAI\Workflow\Executor\ExecutionRequest::resume(['complete' => true]));

        $this->assertFalse($state->isInterrupted());
        $this->assertCount(2, $channel->getSuspensions());
        $this->assertCount(1, $channel->getCompletions());
        $this->assertSame($workflow->getWorkflowId(), $channel->getCompletions()[0]->workflowId);
        $this->assertEquals($state, $channel->getCompletions()[0]->state);
    }

    public function test_node_failure_fires_failed_with_the_exception_and_still_propagates(): void
    {
        $channel = new FakeChannel();
        $workflow = Workflow::make('test-workflow')
            ->addNodes([new ThrowingNode()])
            ->setChannel(fn (): StreamingChannelInterface => $channel);

        $caught = null;
        try {
            $workflow->run();
        } catch (Throwable $e) {
            $caught = $e;
        }

        $this->assertInstanceOf(RuntimeException::class, $caught);
        $this->assertSame('node exploded', $caught->getMessage());

        // failed() is notification only — the same exception reached the caller.
        $this->assertCount(1, $channel->getFailures());
        $this->assertSame($caught, $channel->getFailures()[0]->exception);
        $this->assertSame($workflow->getWorkflowId(), $channel->getFailures()[0]->workflowId);
        $this->assertSame([], $channel->getCompletions());
        $this->assertSame([], $channel->getSuspensions());
    }

    public function test_resume_segment_receives_only_post_resume_items(): void
    {
        $firstSegment = new FakeChannel();
        $workflow = Workflow::make('test-workflow')
            ->addNodes([new PreStreamNode(), new InterruptableNode(), new PostStreamNode()])
            ->setStreamAdapter(fn (): ChunkAdapter => new ChunkAdapter())
            ->setChannel(fn (): StreamingChannelInterface => $firstSegment);

        $this->assertTrue($workflow->run()->isInterrupted());

        $this->assertCount(1, $firstSegment->getSent());
        $this->assertSame('pre', $firstSegment->getSent()[0]->data['payload']);
        $this->assertCount(1, $firstSegment->getSuspensions());

        // Crash-replayed / cached steps yield nothing, so the resume segment's
        // channel never re-broadcasts the pre-suspension stream.
        $resumeSegment = new FakeChannel();
        $workflow->setChannel(fn (): StreamingChannelInterface => $resumeSegment);
        $state = $workflow->run(\NeuronAI\Workflow\Executor\ExecutionRequest::resume([]));

        $this->assertFalse($state->isInterrupted());
        $this->assertCount(1, $resumeSegment->getSent());
        $this->assertSame('post', $resumeSegment->getSent()[0]->data['payload']);
        $this->assertCount(1, $resumeSegment->getCompletions());
        $this->assertSame([], $resumeSegment->getSuspensions());
    }

    public function test_terminal_failure_is_caught_and_reported_never_thrown(): void
    {
        $channel = new CallbackChannel(
            onCompleted: function (WorkflowState $state, string $runId): void {
                throw new RuntimeException('terminal transport down');
            },
        );

        $errors = [];
        $workflow = Workflow::make('test-workflow')
            ->addNodes([new ChunkStreamingNode(1)])
            ->setChannel(fn (): StreamingChannelInterface => $channel);
        $workflow->subscribe(ChannelError::class, function (ChannelError $error) use (&$errors): void {
            $errors[] = $error;
        });

        $state = $workflow->run();

        $this->assertFalse($state->isInterrupted());
        $this->assertCount(1, $errors);
        $this->assertSame('terminal transport down', $errors[0]->exception->getMessage());
    }

    public function test_a_failing_channel_error_listener_never_fails_the_run(): void
    {
        $channel = FakeChannel::make()->setThrowOnSend(new RuntimeException('transport down'));
        $listenerFailure = new LogicException('error reporter down');

        $reported = [];
        $workflow = Workflow::make('test-workflow')
            ->addNodes([new ChunkStreamingNode(2)])
            ->setStreamAdapter(fn (): ChunkAdapter => new ChunkAdapter())
            ->setChannel(fn (): StreamingChannelInterface => $channel)
            ->subscribe(ChannelError::class, function () use ($listenerFailure): void {
                throw $listenerFailure;
            })
            ->subscribe(WorkflowError::class, function (WorkflowError $error) use (&$reported): void {
                $reported[] = $error->exception;
            });

        $state = $workflow->run();

        // Reporting follows the executor's policy: the listener failure is
        // itself reported, once per delivery, and the run still completes.
        $this->assertSame(WorkflowStatus::Completed, $state->getStatus());
        $this->assertSame([$listenerFailure, $listenerFailure], $reported);
        $this->assertCount(1, $channel->getCompletions());
    }
}
