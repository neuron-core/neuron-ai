<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Streaming;

use NeuronAI\Testing\FakeChannel;
use NeuronAI\Tests\Workflow\Channel\Stub\PostStreamNode;
use NeuronAI\Tests\Workflow\Channel\Stub\PreStreamNode;
use NeuronAI\Tests\Workflow\Executor\Stub\ChunkEvent;
use NeuronAI\Tests\Workflow\Streaming\Stub\FramingAdapter;
use NeuronAI\Tests\Workflow\Stub\InterruptableNode;
use NeuronAI\Workflow\Events\InterruptEvent;
use NeuronAI\Workflow\Executor\ExecutionRequest;
use NeuronAI\Workflow\Streaming\Channel\CallbackChannel;
use NeuronAI\Workflow\Streaming\Channel\StreamingChannelInterface;
use NeuronAI\Workflow\Streaming\ProtocolEvent;
use NeuronAI\Workflow\Workflow;
use NeuronAI\Workflow\WorkflowState;
use PHPUnit\Framework\TestCase;

use function array_keys;
use function array_map;
use function array_values;
use function iterator_to_array;

class SegmentOutputTest extends TestCase
{
    protected function workflow(): Workflow
    {
        return Workflow::make('segment-output')
            ->addNodes([new PreStreamNode(), new InterruptableNode(), new PostStreamNode()]);
    }

    /**
     * @param array<int, ProtocolEvent> $events
     * @return list<string>
     */
    protected function types(array $events): array
    {
        return array_map(static fn (ProtocolEvent $event): string => $event->type, array_values($events));
    }

    public function test_native_output_is_numbered_across_nodes_up_to_the_interruption(): void
    {
        $workflow = $this->workflow();

        $suspended = iterator_to_array($workflow->events());
        $this->assertSame([0, 1], array_keys($suspended));
        $this->assertEquals(new ChunkEvent('pre'), $suspended[0]);
        $this->assertInstanceOf(InterruptEvent::class, $suspended[1]);
        $this->assertSame('human input needed', $suspended[1]->request->getMessage());

        $continued = iterator_to_array($workflow->events(ExecutionRequest::resume([])));
        $this->assertSame([0], array_keys($continued));
        $this->assertEquals(new ChunkEvent('post'), $continued[0]);
    }

    public function test_adapted_output_is_numbered_contiguously_and_mirrored_to_the_channel(): void
    {
        $channel = new FakeChannel();
        $workflow = $this->workflow()
            ->setStreamAdapter(fn (): FramingAdapter => new FramingAdapter())
            ->setChannel(fn (): StreamingChannelInterface => $channel);

        $suspended = iterator_to_array($workflow->events());
        $this->assertSame([0, 1, 2, 3], array_keys($suspended));
        $this->assertSame(['start', 'chunk-start', 'chunk-end', 'paused'], $this->types($suspended));
        $this->assertSame(array_values($suspended), $channel->getSent());

        $continued = iterator_to_array($workflow->events(ExecutionRequest::resume([])));
        $this->assertSame([0, 1, 2, 3], array_keys($continued));
        $this->assertSame(['start', 'chunk-start', 'chunk-end', 'end'], $this->types($continued));
        $this->assertSame([...array_values($suspended), ...array_values($continued)], $channel->getSent());
        $this->assertCount(1, $channel->getSuspensions());
        $this->assertCount(1, $channel->getCompletions());
    }

    public function test_the_channel_receives_a_detached_copy_of_the_interrupted_state(): void
    {
        $delivered = [];
        $channel = new CallbackChannel(onInterrupted: function (WorkflowState $state) use (&$delivered): void {
            $state->set('changed_by_channel', true);
            $delivered[] = $state;
        });

        $state = $this->workflow()->setChannel(fn (): StreamingChannelInterface => $channel)->run();

        $this->assertTrue($state->isInterrupted());
        $this->assertCount(1, $delivered);
        $this->assertNotSame($state, $delivered[0]);
        $this->assertFalse($state->has('changed_by_channel'));
        $this->assertSame($state->getWorkflowId(), $delivered[0]->getWorkflowId());
        $this->assertSame($state->getInterruptRequest()->getId(), $delivered[0]->getInterruptRequest()->getId());
    }
}
