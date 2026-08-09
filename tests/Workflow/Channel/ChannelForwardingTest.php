<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Channel;

use Generator;
use NeuronAI\Observability\Events\ChannelError;
use NeuronAI\Testing\FakeChannel;
use NeuronAI\Tests\Workflow\Executor\Stubs\ChunkEvent;
use NeuronAI\Tests\Workflow\Stubs\FirstEvent;
use NeuronAI\Tests\Workflow\Stubs\InterruptableNode;
use NeuronAI\Tests\Workflow\Stubs\NodeOne;
use NeuronAI\Tests\Workflow\Stubs\NodeThree;
use NeuronAI\Tests\Workflow\Stubs\SecondEvent;
use NeuronAI\Workflow\Channel\CallbackChannel;
use NeuronAI\Workflow\Events\InterruptEvent;
use NeuronAI\Workflow\Events\StartEvent;
use NeuronAI\Workflow\Events\StopEvent;
use NeuronAI\Workflow\Interrupt\ApprovalRequest;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\Workflow;
use NeuronAI\Workflow\WorkflowState;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

use function array_map;
use function count;

class ChunkStreamingNode extends Node
{
    public function __construct(protected int $chunks = 3, protected string $prefix = 'chunk')
    {
    }

    public function __invoke(StartEvent $event, WorkflowState $state): Generator
    {
        for ($i = 1; $i <= $this->chunks; $i++) {
            yield new ChunkEvent($this->prefix . '-' . $i);
        }

        return new StopEvent();
    }
}

class SharedRequestInterruptNode extends Node
{
    public function __construct(protected ApprovalRequest $request)
    {
    }

    public function __invoke(FirstEvent $event, WorkflowState $state): SecondEvent
    {
        $this->interrupt($this->request);

        return new SecondEvent('resumed');
    }
}

class TwoStageInterruptNode extends Node
{
    public function __invoke(FirstEvent $event, WorkflowState $state): SecondEvent
    {
        $payload = $this->interrupt(new ApprovalRequest('stage one'));

        if (!isset($payload['complete'])) {
            $this->interrupt(new ApprovalRequest('stage two'));
        }

        return new SecondEvent('resumed');
    }
}

class PreStreamNode extends Node
{
    public function __invoke(StartEvent $event, WorkflowState $state): Generator
    {
        yield new ChunkEvent('pre');

        return new FirstEvent();
    }
}

class PostStreamNode extends Node
{
    public function __invoke(SecondEvent $event, WorkflowState $state): Generator
    {
        yield new ChunkEvent('post');

        return new StopEvent();
    }
}

class ThrowingNode extends Node
{
    public function __invoke(StartEvent $event, WorkflowState $state): StopEvent
    {
        throw new RuntimeException('node exploded');
    }
}

class ChannelForwardingTest extends TestCase
{
    // ------------------------------------------------------------------
    // Phase 1 — the seam
    // ------------------------------------------------------------------

    public function testWiredChannelReceivesEveryYieldedItemInOrderViaRun(): void
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
        $this->assertSame($workflow->getRunId(), $channel->completions[0]['runId']);
        $this->assertSame([], $channel->suspensions);
        $this->assertSame([], $channel->failures);
    }

    public function testWiredChannelReceivesItemsViaCallerHeldGenerator(): void
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

    public function testChannelSendFailuresNeverFailTheRunAndEveryFailureIsDispatched(): void
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

    public function testSuspensionDeliversTheLiveRequestInstanceAndRunId(): void
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
        $this->assertSame($workflow->getRunId(), $channel->suspensions[0]['runId']);

        // …and never the InterruptEvent — nor does a suspended segment complete.
        $this->assertSame([], $channel->sent);
        $this->assertSame([], $channel->completions);

        // Pull consumers still receive the InterruptEvent terminal, unchanged.
        $this->assertInstanceOf(InterruptEvent::class, $pulled[count($pulled) - 1]);
    }

    public function testReSuspensionFiresSuspendedAgainForUpsertByRunId(): void
    {
        $channel = new FakeChannel();
        $workflow = Workflow::make()
            ->addNodes([new NodeOne(), new TwoStageInterruptNode(), new NodeThree()])
            ->setChannel($channel);

        $workflow->run();
        // An incomplete payload re-suspends: consumers upsert by runId, never append.
        $workflow->resume(['partial' => true]);

        $this->assertCount(2, $channel->suspensions);
        $this->assertInstanceOf(ApprovalRequest::class, $channel->suspensions[0]['request']);
        $this->assertInstanceOf(ApprovalRequest::class, $channel->suspensions[1]['request']);
        $this->assertSame('stage one', $channel->suspensions[0]['request']->getMessage());
        $this->assertSame('stage two', $channel->suspensions[1]['request']->getMessage());
        $this->assertSame($channel->suspensions[0]['runId'], $channel->suspensions[1]['runId']);
        $this->assertCount(0, $channel->completions);

        $state = $workflow->resume(['complete' => true]);

        $this->assertFalse($state->isInterrupted());
        $this->assertCount(2, $channel->suspensions);
        $this->assertCount(1, $channel->completions);
        $this->assertSame($workflow->getRunId(), $channel->completions[0]['runId']);
        $this->assertSame($state, $channel->completions[0]['state']);
    }

    public function testNodeFailureFiresFailedWithTheExceptionAndStillPropagates(): void
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
        $this->assertSame($workflow->getRunId(), $channel->failures[0]['runId']);
        $this->assertSame([], $channel->completions);
        $this->assertSame([], $channel->suspensions);
    }

    public function testResumeSegmentReceivesOnlyPostResumeItems(): void
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
        $state = $workflow->resume([]);

        $this->assertFalse($state->isInterrupted());
        $this->assertCount(1, $resumeSegment->sent);
        $this->assertInstanceOf(ChunkEvent::class, $resumeSegment->sent[0]);
        $this->assertSame('post', $resumeSegment->sent[0]->payload);
        $this->assertCount(1, $resumeSegment->completions);
        $this->assertSame([], $resumeSegment->suspensions);
    }

    public function testTerminalFailureIsCaughtAndReportedNeverThrown(): void
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
