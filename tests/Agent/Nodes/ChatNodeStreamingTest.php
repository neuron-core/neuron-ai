<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Agent\Nodes;

use NeuronAI\Agent\InferenceRequest;
use NeuronAI\Workflow\NodeContext;
use NeuronAI\Agent\AgentState;
use NeuronAI\Chat\History\ChatHistory;
use NeuronAI\Chat\History\InMemoryMessageStore;
use NeuronAI\Agent\Events\AgentOutputEvent;
use NeuronAI\Agent\Events\AIInferenceEvent;
use NeuronAI\Agent\Nodes\ChatNode;
use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Providers\ProviderResponse;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Tests\Support\WorkflowTestStore;
use NeuronAI\Workflow\Persistence\InMemoryPersistence;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

class ChatNodeStreamingTest extends TestCase
{
    #[TestWith([false])]
    #[TestWith([true])]
    public function test_live_inference_yields_chunks_only_when_streaming_and_records_response(bool $stream): void
    {
        $chatHistory = new ChatHistory(new InMemoryMessageStore(), 'thread');
        $provider = new FakeAIProvider(new AssistantMessage('Hello world'));
        $provider->setStreamChunkSize(5);

        $node = new ChatNode($provider, $chatHistory);
        $state = new AgentState();

        $state->request = new InferenceRequest(instructions: 'Test', tools: []);
        $event = new AIInferenceEvent();
        $state->request->options->stream = $stream;
        $state->request->messages = [new UserMessage('hi')];

        $node->setWorkflowContext(new NodeContext($state, $event));

        $generator = $node($event, $state);

        $chunks = [];
        foreach ($generator as $chunk) {
            $chunks[] = $chunk;
        }
        $return = $generator->getReturn();

        if ($stream) {
            $this->assertNotEmpty($chunks);
            $this->assertInstanceOf(TextChunk::class, $chunks[0]);
        } else {
            $this->assertSame([], $chunks);
        }

        $provider->assertMethodCallCount($stream ? 'stream' : 'chat', 1);
        $provider->assertMethodCallCount($stream ? 'chat' : 'stream', 0);
        $this->assertCount(2, $chatHistory->getMessages());

        // The final response was captured on state and handed off to output.
        $this->assertInstanceOf(ProviderResponse::class, $state->getResponse());
        $this->assertInstanceOf(AgentOutputEvent::class, $return);
    }

    #[TestWith([false, false])]
    #[TestWith([false, true])]
    #[TestWith([true, false])]
    #[TestWith([true, true])]
    public function test_recovery_serves_cached_response_across_transports(bool $stream, bool $replayStream): void
    {
        $chatHistory = new ChatHistory(new InMemoryMessageStore(), 'thread');
        // A provider stream is non-resumable, so only the terminal response is
        // durable. After a crash between the memoize() commit and the node-step
        // commit, re-running the node on a fresh engine (same persistence) must
        // recall the cached response and NOT re-invoke the provider — the queue
        // holds a single response, so a second stream() call would throw.
        $provider = new FakeAIProvider(new AssistantMessage('Completed answer'));
        $provider->setStreamChunkSize(4);

        $runId = 'streaming_recovery_test';
        $persistence = new InMemoryPersistence();
        $stepId = ChatNode::class . '-0';

        $state = new AgentState();
        $state->setExecutionMetadata($runId, $runId, 1);

        $state->request = new InferenceRequest(instructions: 'Test', tools: []);
        $event = new AIInferenceEvent();
        $state->request->options->stream = $stream;
        $state->request->messages = [new UserMessage('hi')];

        // Run 1: record the response as a durable memo.
        $node1 = new ChatNode($provider, $chatHistory);
        $node1->setWorkflowContext(new NodeContext($state, $event, null, false, WorkflowTestStore::memoizer($persistence, $runId, $stepId)));

        $generator1 = $node1($event, $state);
        foreach ($generator1 as $_) {
            $_ = null; // defeat rector dead-code removal
        }
        $firstReturn = $generator1->getReturn();

        $this->assertInstanceOf(AgentOutputEvent::class, $firstReturn);
        $provider->assertMethodCallCount($stream ? 'stream' : 'chat', 1);
        $firstResponse = $state->getResponse();
        $this->assertNotNull($firstResponse);

        // Recovery: brand-new engine, same persistence, FRESH state (simulates a
        // process restart mid-node, after the inference memo committed but before
        // the node step committed — so the prior assistant message was never
        // persisted and the response must come from the memo, not from state).
        $node2 = new ChatNode($provider, $chatHistory);
        $state2 = new AgentState();
        $state2->request = clone $state->request;
        $state2->request->options->stream = $replayStream;
        $state2->setExecutionMetadata($runId, $runId, 1);
        $node2->setWorkflowContext(new NodeContext($state2, $event, null, false, WorkflowTestStore::memoizer($persistence, $runId, $stepId)));

        $generator2 = $node2($event, $state2);

        $replayedChunks = [];
        foreach ($generator2 as $chunk) {
            $replayedChunks[] = $chunk;
        }
        $secondReturn = $generator2->getReturn();

        // Replay reuses the response even when the transport changes.
        $provider->assertMethodCallCount($stream ? 'stream' : 'chat', 1);

        // No chunks are re-yielded on recovery — there is no live consumer and
        // the stream is non-replayable; the cached response is served directly.
        $this->assertSame([], $replayedChunks);

        $provider->assertMethodCallCount($stream ? 'chat' : 'stream', 0);
        $this->assertCount(2, $chatHistory->getMessages());

        // The same terminal response drives routing.
        $this->assertInstanceOf(AgentOutputEvent::class, $secondReturn);
        $this->assertSame(
            $firstResponse->message()->getContent(),
            $state2->getResponse()->message()->getContent(),
        );
    }
}
