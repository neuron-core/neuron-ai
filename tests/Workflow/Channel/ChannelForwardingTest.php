<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Channel;

use NeuronAI\Tests\Workflow\Channel\Stub\ChunkStreamingNode;
use NeuronAI\Tests\Workflow\Channel\Stub\PostStreamNode;
use NeuronAI\Tests\Workflow\Channel\Stub\PreStreamNode;
use NeuronAI\Tests\Workflow\Channel\Stub\SharedRequestInterruptNode;
use NeuronAI\Tests\Workflow\Channel\Stub\ThrowingNode;
use NeuronAI\Tests\Workflow\Channel\Stub\TwoStageInterruptNode;
use NeuronAI\Observability\Events\ChannelError;
use NeuronAI\Testing\FakeChannel;
use NeuronAI\Tests\Workflow\Executor\Stub\ChunkEvent;
use NeuronAI\Tests\Workflow\Stub\InterruptableNode;
use NeuronAI\Tests\Workflow\Stub\NodeOne;
use NeuronAI\Tests\Workflow\Stub\NodeThree;
use NeuronAI\Workflow\Channel\CallbackChannel;
use NeuronAI\Workflow\Events\InterruptEvent;
use NeuronAI\Workflow\Interrupt\ApprovalRequest;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\Resume\ResumeInput;
use NeuronAI\Workflow\Workflow;
use NeuronAI\Workflow\WorkflowState;
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
            ->setChannel($channel);

        $workflow->run();

        $payloads = array_map(
            static fn (object $item): string => $item instanceof ChunkEvent ? $item->payload : $item::class,
            $channel->sent,
        );

        $this->assertSame(['chunk-1', 'chunk-2', 'chunk-3'], $payloads);
        $this->assertCount(1, $channel->completions);
        $this->assertSame($workflow->getWorkflowId(), $channel->completions[0]['workflowId']);
        $this->assertSame([], $channel->suspensions);
        $this->assertSame([], $channel->failures);
    }

    public function test_wired_channel_receives_items_via_caller_held_generator(): void
    {
        $channel = new FakeChannel();
        $workflow = Workflow::make()
            ->addNodes([new ChunkStreamingNode(2)])
            ->setChannel($channel);

        $pulled = [];
        foreach ($workflow->events() as $event) {
            $pulled[] = $event;
        }

        // Push and pull consumers see the same items — same instances, same order.
        $this->assertSame($pulled, $channel->sent);
        $this->assertCount(1, $channel->completions);
    }

    public function test_channel_send_failures_never_fail_the_run_and_every_failure_is_dispatched(): void
    {
        $channel = new FakeChannel();
        $channel->throwOnSend = new RuntimeException('transport down');

        $errors = [];
        $workflow = Workflow::make()
            ->addNodes([new ChunkStreamingNode(5)])
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
            $this->assertSame($channel->throwOnSend, $error->exception);
        }
        // The terminal is still delivered — failures never lose the run.
        $this->assertCount(1, $channel->completions);
    }

    // ------------------------------------------------------------------
    // Phase 2 — terminals
    // ------------------------------------------------------------------

    public function test_suspension_delivers_the_live_request_instance_and_run_id(): void
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

        // The channel receives the SAME live request object the node created…
        $this->assertCount(1, $channel->suspensions);
        $this->assertSame($request, $channel->suspensions[0]['request']);
        $this->assertSame($workflow->getWorkflowId(), $channel->suspensions[0]['workflowId']);

        // …and never the InterruptEvent — nor does a suspended segment complete.
        $this->assertSame([], $channel->sent);
        $this->assertSame([], $channel->completions);

        // Pull consumers still receive the InterruptEvent terminal, unchanged.
        $this->assertInstanceOf(InterruptEvent::class, $pulled[count($pulled) - 1]);
    }

    public function test_re_suspension_fires_suspended_again_for_upsert_by_run_id(): void
    {
        $channel = new FakeChannel();
        $workflow = Workflow::make()
            ->addNodes([new NodeOne(), new TwoStageInterruptNode(), new NodeThree()])
            ->setChannel($channel);

        $workflow->run();
        // An incomplete payload re-suspends: consumers upsert by runId, never append.
        $workflow->resume([ResumeInput::event(1, ['partial' => true])]);

        $this->assertCount(2, $channel->suspensions);
        $this->assertInstanceOf(ApprovalRequest::class, $channel->suspensions[0]['request']);
        $this->assertInstanceOf(ApprovalRequest::class, $channel->suspensions[1]['request']);
        $this->assertSame('stage one', $channel->suspensions[0]['request']->getMessage());
        $this->assertSame('stage two', $channel->suspensions[1]['request']->getMessage());
        $this->assertSame($channel->suspensions[0]['workflowId'], $channel->suspensions[1]['workflowId']);
        $this->assertCount(0, $channel->completions);

        $state = $workflow->resume([ResumeInput::event(2, ['complete' => true])]);

        $this->assertFalse($state->isInterrupted());
        $this->assertCount(2, $channel->suspensions);
        $this->assertCount(1, $channel->completions);
        $this->assertSame($workflow->getWorkflowId(), $channel->completions[0]['workflowId']);
        $this->assertSame($state, $channel->completions[0]['state']);
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
        $this->assertCount(1, $channel->failures);
        $this->assertSame($caught, $channel->failures[0]['exception']);
        $this->assertSame($workflow->getWorkflowId(), $channel->failures[0]['workflowId']);
        $this->assertSame([], $channel->completions);
        $this->assertSame([], $channel->suspensions);
    }

    public function test_resume_segment_receives_only_post_resume_items(): void
    {
        $firstSegment = new FakeChannel();
        $workflow = Workflow::make()
            ->addNodes([new PreStreamNode(), new InterruptableNode(), new PostStreamNode()])
            ->setChannel($firstSegment);

        $workflow->run();

        $this->assertCount(1, $firstSegment->sent);
        $this->assertInstanceOf(ChunkEvent::class, $firstSegment->sent[0]);
        $this->assertSame('pre', $firstSegment->sent[0]->payload);
        $this->assertCount(1, $firstSegment->suspensions);

        // Crash-replayed / cached steps yield nothing, so the resume segment's
        // channel never re-broadcasts the pre-suspension stream.
        $resumeSegment = new FakeChannel();
        $workflow->setChannel($resumeSegment);
        $state = $workflow->resume([ResumeInput::event(1, [])]);

        $this->assertFalse($state->isInterrupted());
        $this->assertCount(1, $resumeSegment->sent);
        $this->assertInstanceOf(ChunkEvent::class, $resumeSegment->sent[0]);
        $this->assertSame('post', $resumeSegment->sent[0]->payload);
        $this->assertCount(1, $resumeSegment->completions);
        $this->assertSame([], $resumeSegment->suspensions);
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
}
