<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Agent;

use NeuronAI\Agent\Agent;
use NeuronAI\Chat\History\InMemoryChatHistory;
use NeuronAI\Chat\History\SQLChatHistory;
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
use NeuronAI\Tests\Agent\Stub\SearchTool;
use NeuronAI\Tests\StructuredOutput\Stub\User;
use NeuronAI\Tools\ToolCall;
use NeuronAI\Workflow\Interrupt\ApprovalRequest;
use NeuronAI\Workflow\Interrupt\ResumeInput;
use NeuronAI\Workflow\Persistence\InMemoryPersistence;
use PDO;
use PHPUnit\Framework\TestCase;

use function array_filter;
use function iterator_to_array;

/**
 * Phase 5 acceptance: the functional model "new turn → new run; answer →
 * resume" — a suspended run continues from a BLANK factory through the single
 * mode-agnostic resume() verb, with the run's intent and identity supplied by
 * its ignition record, never by the caller.
 */
class AgentResumeTest extends TestCase
{
    protected function sqlite(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE chat_messages (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            thread_id TEXT, role TEXT, content TEXT, meta TEXT
        )');

        return $pdo;
    }

    public function test_streamed_approval_run_wakes_from_a_blank_factory(): void
    {
        $persistence = new InMemoryPersistence();

        // ── Ignition: streamed turn, approval-gated tool, thread identity set.
        $searchTool = new SearchTool();
        $searchTool->requireApproval();

        $provider1 = new FakeAIProvider(
            new ToolCallMessage(null, [
                ToolCall::make($searchTool->getName(), 'call_1', ['query' => 'PHP frameworks']),
            ]),
        );
        $provider1->setStreamChunkSize(5);

        $agent1 = Agent::make();
        $agent1->setAiProvider($provider1)
            ->setInstructions('You are a search assistant.');
        $agent1->addTool($searchTool);
        $agent1->setPersistence($persistence);
        // Ignition: durable per-thread storage; the pre-bound history
        // declares the thread identity.
        $pdo = $this->sqlite();
        $agent1->setChatHistory(new SQLChatHistory($pdo, 'thread-1'));
        $agent1->setChannel(new FakeChannel());

        $handler1 = $agent1->stream(new UserMessage('Search for PHP frameworks'));
        iterator_to_array($handler1);

        $this->assertTrue($agent1->getState()->isInterrupted());

        // ── Resume: a BLANK factory — the thread's workflow ID only, no threadId set.
        $wakeTool = new SearchTool();
        $wakeTool->requireApproval();

        $provider2 = new FakeAIProvider(new AssistantMessage('Here is what I found.'));
        $provider2->setStreamChunkSize(5);

        $channel = new FakeChannel();

        $agent2 = Agent::make(workflowId: 'thread-1');
        $agent2->setAiProvider($provider2)
            ->setInstructions('You are a search assistant.');
        $agent2->addTool($wakeTool);
        $agent2->setPersistence($persistence);
        // Unbound history: the threadId arrives from the ignition record and
        // is bound by the framework before the history is touched.
        $agent2->setChatHistory(new SQLChatHistory($pdo));
        $agent2->setChannel($channel);

        // The approval wrapper hides the signal name; events() selects streaming.
        $handler2 = $agent2->toolApprovalDecisions(['call_1' => 'approve'])
            ->events();
        $chunks = iterator_to_array($handler2);
        $state = $handler2->getReturn();

        // The run completed, on the right thread, in the right mode.
        $this->assertFalse($state->isInterrupted());
        $this->assertSame('thread-1', $agent2->getChatHistory()->getThreadId());

        // Stream intent survived suspend → resume through both pull and push delivery.
        $this->assertNotEmpty(
            array_filter($chunks, fn (object $item): bool => $item instanceof TextChunk)
        );

        $this->assertNotEmpty(
            array_filter($channel->sent, fn (object $item): bool => $item instanceof TextChunk)
        );
        $this->assertCount(1, $channel->completions);

        // The final message landed in the thread's history.
        $this->assertSame(
            'Here is what I found.',
            $state->getMessage()->getContent()
        );
        $this->assertSame(
            'Here is what I found.',
            $agent2->getChatHistory()->getLastMessage()->getContent()
        );
    }

    public function test_structured_run_wakes_and_returns_the_output_via_state(): void
    {
        $persistence = new InMemoryPersistence();
        $workflowId = 'wake_structured_e2e';
        $history = new InMemoryChatHistory($workflowId);

        $searchTool = new SearchTool();
        $searchTool->requireApproval();

        $agent1 = Agent::make(workflowId: $workflowId);
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

        $agent2 = Agent::make(workflowId: $workflowId);
        $agent2->setAiProvider(new FakeAIProvider(new AssistantMessage('{"name": "Alice"}')))
            ->setInstructions('Extract the user.');
        $agent2->addTool($wakeTool);
        $agent2->setPersistence($persistence);
        $agent2->setChatHistory($history);

        // Continuation is mode-agnostic: structured intent rides the ignition
        // record, and the output arrives through the state.
        $user = $agent2->toolApprovalDecisions(['call_1' => 'approve'])
            ->run()
            ->get('structured_output');

        $this->assertInstanceOf(User::class, $user);
        $this->assertSame('Alice', $user->name);
    }

    public function test_incomplete_decision_set_re_suspends_the_resume(): void
    {
        $persistence = new InMemoryPersistence();
        $workflowId = 'wake_incomplete_e2e';
        $history = new InMemoryChatHistory($workflowId);

        $searchTool = new SearchTool();
        $searchTool->requireApproval();

        $agent1 = Agent::make(workflowId: $workflowId);
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

        $state1 = $agent1->chat(new UserMessage('Run both searches'));
        $this->assertTrue($state1->isInterrupted());

        // One decision out of two: the resume re-suspends (silence is never consent).
        $partial = $agent1->run([ResumeInput::event((new ApprovalRequest('test'))->withId(1), ['call_a' => 'approve'])]);
        $this->assertTrue($partial->isInterrupted());
        $this->assertNotNull($partial->getInterruptRequest());

        // The full, restated decision set completes the run.
        $complete = $agent1->run([ResumeInput::event((new ApprovalRequest('test'))->withId(2), [
            'call_a' => 'approve',
            'call_b' => 'approve',
        ])]);
        $this->assertSame('Both searches done.', $complete->getMessage()->getContent());
    }

    public function test_structured_rag_run_wakes_with_intent_intact(): void
    {
        $persistence = new InMemoryPersistence();
        $workflowId = 'wake_rag_structured_e2e';
        $history = new InMemoryChatHistory($workflowId);

        $searchTool = new SearchTool();
        $searchTool->requireApproval();

        $rag1 = RAG::make(workflowId: $workflowId);
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

        $rag2 = RAG::make(workflowId: $workflowId);
        $rag2->setAiProvider(new FakeAIProvider(new AssistantMessage('{"name": "Alice"}')))
            ->setInstructions('Extract the user from the context.');
        $rag2->addTool($wakeTool);
        $rag2->setEmbeddingsProvider(new FakeEmbeddingsProvider());
        $rag2->setVectorStore(new FakeVectorStore([
            new Document('Alice is the user in question.'),
        ]));
        $rag2->setPersistence($persistence);
        $rag2->setChatHistory($history);

        $user = $rag2->run([ResumeInput::event((new ApprovalRequest('test'))->withId(1), ['call_1' => 'approve'])])->get('structured_output');

        $this->assertInstanceOf(User::class, $user);
        $this->assertSame('Alice', $user->name);
    }

    public function test_resume_accepts_numeric_tool_call_ids(): void
    {
        $persistence = new InMemoryPersistence();
        $history = new InMemoryChatHistory('numeric-call-id');
        $searchTool = new SearchTool();
        $searchTool->requireApproval();

        $agent = Agent::make(workflowId: 'numeric-call-id');
        $agent->setAiProvider(new FakeAIProvider(
            new ToolCallMessage(null, [
                ToolCall::make($searchTool->getName(), '123', ['query' => 'PHP']),
            ]),
            new AssistantMessage('Search complete.'),
        ))->setInstructions('Search once.');
        $agent->addTool($searchTool);
        $agent->setPersistence($persistence);
        $agent->setChatHistory($history);

        $suspended = $agent->chat(new UserMessage('Search'));
        $this->assertTrue($suspended->isInterrupted());

        // PHP converts a numeric-string JSON object key to an integer array key.
        $completed = $agent->toolApprovalDecisions([123 => 'approve'])->run();

        $this->assertFalse($completed->isInterrupted());
        $this->assertSame('Search complete.', $completed->getMessage()->getContent());
    }

}
