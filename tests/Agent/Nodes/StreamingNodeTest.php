<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Agent\Nodes;

use NeuronAI\Agent\AgentState;
use NeuronAI\Agent\Events\AIInferenceEvent;
use NeuronAI\Agent\Nodes\StreamingNode;
use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Providers\ProviderResponse;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Workflow\Events\StopEvent;
use NeuronAI\Workflow\Executor\LocalStepEngine;
use NeuronAI\Workflow\Executor\StepMemoizer;
use NeuronAI\Workflow\Persistence\InMemoryPersistence;
use PHPUnit\Framework\TestCase;

class StreamingNodeTest extends TestCase
{
    public function test_live_stream_yields_chunks_and_records_response(): void
    {
        $provider = new FakeAIProvider(new AssistantMessage('Hello world'));
        $provider->setStreamChunkSize(5);

        $node = new StreamingNode($provider);
        $state = new AgentState();

        $event = new AIInferenceEvent(instructions: 'Test', tools: []);
        $event->setMessages(new UserMessage('hi'));

        $node->setWorkflowContext($state, $event);

        $generator = $node($event, $state);

        $chunks = [];
        foreach ($generator as $chunk) {
            $chunks[] = $chunk;
        }
        $return = $generator->getReturn();

        // The live consumer receives real streamed chunks.
        $this->assertNotEmpty($chunks);
        $this->assertInstanceOf(TextChunk::class, $chunks[0]);

        // The inference was invoked exactly once.
        $provider->assertMethodCallCount('stream', 1);

        // The final response was captured on state and the node routed to Stop.
        $this->assertInstanceOf(ProviderResponse::class, $state->getResponse());
        $this->assertInstanceOf(StopEvent::class, $return);
    }

    public function test_recovery_serves_cached_response_without_re_streaming(): void
    {
        // A provider stream is non-resumable, so only the terminal response is
        // durable. After a crash between the memoize() commit and the node-step
        // commit, re-running the node on a fresh engine (same persistence) must
        // recall the cached response and NOT re-invoke the provider — the queue
        // holds a single response, so a second stream() call would throw.
        $provider = new FakeAIProvider(new AssistantMessage('Completed answer'));
        $provider->setStreamChunkSize(4);

        $workflowId = 'streaming_recovery_test';
        $persistence = new InMemoryPersistence();
        $stepId = StreamingNode::class . '-0';

        $state = new AgentState();
        $state->set('__workflowId', $workflowId);

        $event = new AIInferenceEvent(instructions: 'Test', tools: []);
        $event->setMessages(new UserMessage('hi'));

        // Run 1: live stream + record the response as a durable memo.
        $engine1 = new LocalStepEngine($persistence);
        $engine1->prepareExecution($workflowId);
        $node1 = new StreamingNode($provider);
        $node1->setWorkflowContext($state, $event, null, false, new StepMemoizer($engine1, $stepId));

        $generator1 = $node1($event, $state);
        foreach ($generator1 as $_) {
            $_ = null; // defeat rector dead-code removal
        }
        $firstReturn = $generator1->getReturn();

        $this->assertInstanceOf(StopEvent::class, $firstReturn);
        $provider->assertMethodCallCount('stream', 1);
        $firstResponse = $state->getResponse();
        $this->assertNotNull($firstResponse);

        // Recovery: brand-new engine, same persistence, FRESH state (simulates a
        // process restart mid-node, after the inference memo committed but before
        // the node step committed — so the prior assistant message was never
        // persisted and the response must come from the memo, not from state).
        $engine2 = new LocalStepEngine($persistence);
        $engine2->prepareExecution($workflowId);
        $node2 = new StreamingNode($provider);
        $state2 = new AgentState();
        $state2->set('__workflowId', $workflowId);
        $node2->setWorkflowContext($state2, $event, null, false, new StepMemoizer($engine2, $stepId));

        $generator2 = $node2($event, $state2);

        $replayedChunks = [];
        foreach ($generator2 as $chunk) {
            $replayedChunks[] = $chunk;
        }
        $secondReturn = $generator2->getReturn();

        // No re-inference: the provider stream is still a single call.
        $provider->assertMethodCallCount('stream', 1);

        // No chunks are re-yielded on recovery — there is no live consumer and
        // the stream is non-replayable; the cached response is served directly.
        $this->assertSame([], $replayedChunks);

        // The same terminal response drives routing.
        $this->assertInstanceOf(StopEvent::class, $secondReturn);
        $this->assertSame(
            $firstResponse->message()->getContent(),
            $state2->getResponse()->message()->getContent(),
        );
    }
}
