<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Channel;

use LogicException;
use NeuronAI\Agent\Interrupt\ApprovalRequest;
use NeuronAI\Observability\Events\AgentError;
use NeuronAI\Observability\Events\ChannelError;
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
use NeuronAI\Workflow\Events\InterruptEvent;
use NeuronAI\Workflow\Streaming\Channel\CallbackChannel;
use NeuronAI\Workflow\Streaming\ProtocolEvent;
use NeuronAI\Workflow\Workflow;
use NeuronAI\Workflow\WorkflowState;
use NeuronAI\Workflow\WorkflowStatus;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;
use function array_map;
use function count;

class ChannelForwardingTest extends TestCase
{
    // ------------------------------------------------------------------
    // Phase 1 — the seam
    // ------------------------------------------------------------------

    public function test_wired_channel_receives_every_yielded_item_in_order_via_run(): void
    {
        $channel = new FakeChannel();
        $workflow = Workflow::make()
            ->addNodes([new ChunkStreamingNode(3)])
            ->setStreamAdapter(new ChunkAdapter())
            ->setChannel($channel);

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

    public function test_wired_channel_receives_items_via_caller_held_generator(): void
    {
        $channel = new FakeChannel();
        $workflow = Workflow::make()
            ->addNodes([new ChunkStreamingNode(2)])
            ->setStreamAdapter(new ChunkAdapter())
            ->setChannel($channel);

        $pulled = [];
        foreach ($workflow->events() as $event) {
            $pulled[] = $event;
        }

        // Push and pull consumers see the same items — same instances, same order.
        $this->assertSame($pulled, $channel->getSent());
        $this->assertCount(1, $channel->getCompletions());
    }

    public function test_channel_send_failures_never_fail_the_run_and_every_failure_is_dispatched(): void
    {
        $failure = new RuntimeException('transport down');
        $channel = FakeChannel::make()->setThrowOnSend($failure);

        $errors = [];
        $workflow = Workflow::make()
            ->addNodes([new ChunkStreamingNode(5)])
            ->setStreamAdapter(new ChunkAdapter())
            ->setChannel($channel);
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
        $workflow = Workflow::make()
            ->addNodes([new ChunkStreamingNode(3)])
            ->setChannel($channel);

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
        $workflow = Workflow::make()
            ->addNodes([new NodeOne(), new SharedRequestInterruptNode($request)])
            ->setChannel($channel);

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
        $workflow = Workflow::make()
            ->addNodes([new NodeOne(), new TwoStageInterruptNode(), new NodeThree()])
            ->setChannel($channel);

        $workflow->run();
        // An incomplete payload interrupts again with a new active request.
        $workflow->resume(['partial' => true])->run();

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

        $state = $workflow->resume(['complete' => true])->run();

        $this->assertFalse($state->isInterrupted());
        $this->assertCount(2, $channel->getSuspensions());
        $this->assertCount(1, $channel->getCompletions());
        $this->assertSame($workflow->getWorkflowId(), $channel->getCompletions()[0]->workflowId);
        $this->assertSame($state, $channel->getCompletions()[0]->state);
    }

    public function test_node_failure_fires_failed_with_the_exception_and_still_propagates(): void
    {
        $channel = new FakeChannel();
        $workflow = Workflow::make()
            ->addNodes([new ThrowingNode()])
            ->setChannel($channel);

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
        $workflow = Workflow::make()
            ->addNodes([new PreStreamNode(), new InterruptableNode(), new PostStreamNode()])
            ->setStreamAdapter(new ChunkAdapter())
            ->setChannel($firstSegment);

        $workflow->run();

        $this->assertCount(1, $firstSegment->getSent());
        $this->assertSame('pre', $firstSegment->getSent()[0]->data['payload']);
        $this->assertCount(1, $firstSegment->getSuspensions());

        // Crash-replayed / cached steps yield nothing, so the resume segment's
        // channel never re-broadcasts the pre-suspension stream.
        $resumeSegment = new FakeChannel();
        $workflow->setChannel($resumeSegment);
        $state = $workflow->resume([])->run();

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
        $workflow = Workflow::make()
            ->addNodes([new ChunkStreamingNode(1)])
            ->setChannel($channel);
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
        $workflow = Workflow::make()
            ->addNodes([new ChunkStreamingNode(2)])
            ->setStreamAdapter(new ChunkAdapter())
            ->setChannel($channel)
            ->subscribe(ChannelError::class, function () use ($listenerFailure): void {
                throw $listenerFailure;
            })
            ->subscribe(AgentError::class, function (AgentError $error) use (&$reported): void {
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
