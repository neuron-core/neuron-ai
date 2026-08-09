<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Agent;

use NeuronAI\Agent\Agent;
use NeuronAI\Chat\History\ChatHistoryInterface;
use NeuronAI\Chat\History\InMemoryChatHistory;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\RAG;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Testing\FakeChannel;
use NeuronAI\Testing\FakeEmbeddingsProvider;
use NeuronAI\Testing\FakeVectorStore;
use NeuronAI\Tests\Agent\Tools\SearchTool;
use NeuronAI\Tests\Stubs\StructuredOutput\User;
use NeuronAI\Tools\ToolCall;
use NeuronAI\Workflow\Persistence\InMemoryPersistence;
use PHPUnit\Framework\TestCase;

/**
 * Phase 5 acceptance: the functional model "new turn → new run; answer →
 * wake" — a suspended run continues from a BLANK factory through the single
 * mode-agnostic wake() verb, with the run's intent and identity supplied by
 * its ignition record, never by the caller.
 */
class AgentWakeTest extends TestCase
{
    /**
     * Chat histories shared across agent instances, keyed by threadId — what
     * a real resolver does against a database.
     *
     * @var array<string, ChatHistoryInterface>
     */
    protected array $histories = [];

    protected function historyResolver(): \Closure
    {
        return function (string $threadId): ChatHistoryInterface {
            return $this->histories[$threadId] ??= new InMemoryChatHistory();
        };
    }

    public function test_streamed_approval_run_wakes_from_a_blank_factory(): void
    {
        $persistence = new InMemoryPersistence();
        $runId = 'wake_stream_e2e';

        // ── Ignition: streamed turn, approval-gated tool, thread identity set.
        $searchTool = new SearchTool();
        $searchTool->requireApproval();

        $provider1 = new FakeAIProvider(
            new ToolCallMessage(null, [
                ToolCall::make($searchTool->getName(), 'call_1', ['query' => 'PHP frameworks']),
            ]),
        );
        $provider1->setStreamChunkSize(5);

        $agent1 = Agent::make(runId: $runId);
        $agent1->setAiProvider($provider1)
            ->setInstructions('You are a search assistant.');
        $agent1->addTool($searchTool);
        $agent1->setPersistence($persistence);
        $agent1->setChatHistory($this->historyResolver());
        $agent1->setChannel(fn (string $threadId): FakeChannel => new FakeChannel());
        $agent1->setThreadId('thread-1');

        $handler1 = $agent1->stream(new UserMessage('Search for PHP frameworks'));
        $handler1->run();

        $this->assertTrue($handler1->interrupted());

        // ── Wake: a BLANK factory — runId only, resolvers wired, no threadId.
        $wakeTool = new SearchTool();
        $wakeTool->requireApproval();

        $provider2 = new FakeAIProvider(new AssistantMessage('Here is what I found.'));
        $provider2->setStreamChunkSize(5);

        $channel = new FakeChannel();

        $agent2 = Agent::make(runId: $runId);
        $agent2->setAiProvider($provider2)
            ->setInstructions('You are a search assistant.');
        $agent2->addTool($wakeTool);
        $agent2->setPersistence($persistence);
        $agent2->setChatHistory($this->historyResolver());
        $agent2->setChannel(fn (string $threadId): FakeChannel => $channel);

        $handler2 = $agent2->wake(['call_1' => 'approve']);

        $chunks = [];
        foreach ($handler2->events() as $item) {
            if ($item instanceof TextChunk) {
                $chunks[] = $item;
            }
        }

        // The run completed, on the right thread, in the right mode.
        $this->assertFalse($handler2->interrupted());
        $this->assertSame('thread-1', $agent2->getThreadId());

        // Stream intent survived suspend → wake: the post-wake inference
        // streamed live chunks to the caller AND to the resolved channel.
        $this->assertNotEmpty($chunks);
        $this->assertNotEmpty(
            array_filter($channel->sent, fn (object $item): bool => $item instanceof TextChunk)
        );
        $this->assertCount(1, $channel->completions);

        // The final message landed in the thread's history.
        $this->assertSame(
            'Here is what I found.',
            $handler2->getMessage()->getContent()
        );
        $this->assertSame(
            'Here is what I found.',
            $this->histories['thread-1']->getLastMessage()->getContent()
        );
    }

    public function test_structured_run_wakes_and_returns_the_output_via_state(): void
    {
        $persistence = new InMemoryPersistence();
        $history = new InMemoryChatHistory();
        $runId = 'wake_structured_e2e';

        $searchTool = new SearchTool();
        $searchTool->requireApproval();

        $agent1 = Agent::make(runId: $runId);
        $agent1->setAiProvider(new FakeAIProvider(
            new ToolCallMessage(null, [
                ToolCall::make($searchTool->getName(), 'call_1', ['query' => 'Alice']),
            ]),
        ))->setInstructions('Extract the user.');
        $agent1->addTool($searchTool);
        $agent1->setPersistence($persistence);
        $agent1->setChatHistory($history);

        // Eager structured() returns no output on suspension — the run paused.
        $output = $agent1->structured(new UserMessage('Who is the user?'), User::class);
        $this->assertNull($output);

        $wakeTool = new SearchTool();
        $wakeTool->requireApproval();

        $agent2 = Agent::make(runId: $runId);
        $agent2->setAiProvider(new FakeAIProvider(new AssistantMessage('{"name": "Alice"}')))
            ->setInstructions('Extract the user.');
        $agent2->addTool($wakeTool);
        $agent2->setPersistence($persistence);
        $agent2->setChatHistory($history);

        // The wake is mode-agnostic: structured intent rides the ignition
        // record, and the output arrives through the handler's state.
        $user = $agent2->wake(['call_1' => 'approve'])->getState()->get('structured_output');

        $this->assertInstanceOf(User::class, $user);
        $this->assertSame('Alice', $user->name);
    }

    public function test_incomplete_decision_set_re_suspends_the_wake(): void
    {
        $persistence = new InMemoryPersistence();
        $history = new InMemoryChatHistory();
        $runId = 'wake_incomplete_e2e';

        $searchTool = new SearchTool();
        $searchTool->requireApproval();

        $agent1 = Agent::make(runId: $runId);
        $agent1->setAiProvider(new FakeAIProvider(
            new ToolCallMessage(null, [
                ToolCall::make($searchTool->getName(), 'call_a', ['query' => 'one']),
                ToolCall::make($searchTool->getName(), 'call_b', ['query' => 'two']),
            ]),
            new AssistantMessage('Both searches done.'),
        ))->setInstructions('Search twice.');
        $agent1->addTool($searchTool);
        $agent1->setPersistence($persistence);
        $agent1->setChatHistory($history);

        $handler1 = $agent1->chat(new UserMessage('Run both searches'));
        $handler1->run();
        $this->assertTrue($handler1->interrupted());

        // One decision out of two: the wake re-suspends (silence is never consent).
        $partial = $agent1->wake(['call_a' => 'approve']);
        $partial->run();
        $this->assertTrue($partial->interrupted());
        $this->assertNotNull($partial->getInterruptRequest());

        // The full, restated decision set completes the run.
        $complete = $agent1->wake(['call_a' => 'approve', 'call_b' => 'approve']);
        $this->assertSame('Both searches done.', $complete->getMessage()->getContent());
    }

    public function test_structured_rag_run_wakes_with_intent_intact(): void
    {
        $persistence = new InMemoryPersistence();
        $history = new InMemoryChatHistory();
        $runId = 'wake_rag_structured_e2e';

        $searchTool = new SearchTool();
        $searchTool->requireApproval();

        $rag1 = RAG::make(runId: $runId);
        $rag1->setAiProvider(new FakeAIProvider(
            new ToolCallMessage(null, [
                ToolCall::make($searchTool->getName(), 'call_1', ['query' => 'Alice']),
            ]),
        ))->setInstructions('Extract the user from the context.');
        $rag1->addTool($searchTool);
        $rag1->setEmbeddingsProvider(new FakeEmbeddingsProvider());
        $rag1->setVectorStore(new FakeVectorStore([
            new Document('Alice is the user in question.'),
        ]));
        $rag1->setPersistence($persistence);
        $rag1->setChatHistory($history);

        // Structured intent must survive the retrieval boundary AND the suspend.
        $output = $rag1->structured(new UserMessage('Who is the user?'), User::class);
        $this->assertNull($output);

        $wakeTool = new SearchTool();
        $wakeTool->requireApproval();

        $rag2 = RAG::make(runId: $runId);
        $rag2->setAiProvider(new FakeAIProvider(new AssistantMessage('{"name": "Alice"}')))
            ->setInstructions('Extract the user from the context.');
        $rag2->addTool($wakeTool);
        $rag2->setEmbeddingsProvider(new FakeEmbeddingsProvider());
        $rag2->setVectorStore(new FakeVectorStore([
            new Document('Alice is the user in question.'),
        ]));
        $rag2->setPersistence($persistence);
        $rag2->setChatHistory($history);

        $user = $rag2->wake(['call_1' => 'approve'])->getState()->get('structured_output');

        $this->assertInstanceOf(User::class, $user);
        $this->assertSame('Alice', $user->name);
    }
}
