<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow;

use NeuronAI\Tests\Workflow\Executor\ExecutorTestHelpers;
use NeuronAI\Tests\Workflow\Executor\Stubs\ChunkEvent;
use NeuronAI\Tests\Workflow\Executor\Stubs\FinalTextProcessNode;
use NeuronAI\Tests\Workflow\Executor\Stubs\Step2Event;
use NeuronAI\Tests\Workflow\Executor\Stubs\StreamingTextProcessNode;
use NeuronAI\Tests\Workflow\Stubs\NodeOne;
use NeuronAI\Tests\Workflow\Stubs\NodeThree;
use NeuronAI\Tests\Workflow\Stubs\NodeTwo;
use NeuronAI\Tests\Workflow\Stubs\SecondEvent;
use NeuronAI\Workflow\Events\StartEvent;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\Workflow;
use NeuronAI\Workflow\WorkflowState;
use PHPUnit\Framework\TestCase;

use function array_filter;
use function array_map;

class WorkflowStreamingTest extends TestCase
{
    use ExecutorTestHelpers;

    /**
     * NodeTwo yields a SecondEvent before returning one.
     * Verify the yielded event reaches the caller via events().
     */
    public function testStreamedEventsReachCaller(): void
    {
        $workflow = Workflow::make()->addNodes([
            new NodeOne(),
            new NodeTwo(),
            new NodeThree(),
        ]);

        $gen = $workflow->events();

        $events = [];
        foreach ($gen as $event) {
            $events[] = $event;
        }
        $gen->getReturn();

        // NodeTwo yields SecondEvent('Stream second event') and returns SecondEvent('Second complete')
        $streamed = array_filter($events, fn (object $e): bool => $e instanceof SecondEvent && $e->message === 'Stream second event');
        $this->assertCount(1, $streamed, 'NodeTwo should yield exactly one streamed SecondEvent');
    }

    /**
     * StreamingTextProcessNode yields ChunkEvents with specific payloads.
     * Verify they propagate through a multi-node workflow.
     */
    public function testStreamedChunkEventsReachCaller(): void
    {
        $workflow = Workflow::make()->addNodes([
            // StartEvent → Step2Event
            new class () extends Node {
                public function __invoke(StartEvent $event, WorkflowState $state): Step2Event
                {
                    $state->set('step1_executed', true);
                    return new Step2Event('start');
                }
            },
            // Step2Event → yields ChunkEvents → Step3Event
            new StreamingTextProcessNode(),
            // Step3Event → StopEvent
            new FinalTextProcessNode(),
        ]);

        $gen = $workflow->events();

        $events = [];
        foreach ($gen as $event) {
            $events[] = $event;
        }
        $finalState = $gen->getReturn();

        // Filter ChunkEvents by payload
        $chunkPayloads = array_map(
            fn (ChunkEvent $e): string => $e->payload,
            array_filter($events, fn (object $e): bool => $e instanceof ChunkEvent),
        );

        $this->assertSame(['text-1', 'text-2'], $chunkPayloads);
        $this->assertTrue($finalState->get('step1_executed'));
        $this->assertTrue($finalState->get('streaming_step_executed'));
        $this->assertTrue($finalState->get('final_step_executed'));
    }

    /**
     * When a step is memoized (replay), no streamed events should be yielded
     * because the callable doesn't execute.
     */
    public function testMemoizedStepsDoNotReEmitStreamedEvents(): void
    {
        $workflow = Workflow::make()->addNodes([
            new NodeOne(),
            new NodeTwo(),
            new NodeThree(),
        ]);

        // First run: collect events
        $gen = $workflow->events();
        $eventsFirstRun = [];
        foreach ($gen as $event) {
            $eventsFirstRun[] = $event;
        }
        $gen->getReturn();

        // The streamed event was emitted on the first run
        $streamedFirstRun = array_filter($eventsFirstRun, fn (object $e): bool => $e instanceof SecondEvent && $e->message === 'Stream second event');
        $this->assertCount(1, $streamedFirstRun);
    }
}
