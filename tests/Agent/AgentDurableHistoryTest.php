<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Agent;

use NeuronAI\Agent\Agent;
use NeuronAI\Agent\Middleware\ToolApproval;
use NeuronAI\Agent\Nodes\ChatNode;
use NeuronAI\Agent\Nodes\ToolNode;
use NeuronAI\Chat\History\SQLChatHistory;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Tests\Agent\Tools\SearchTool;
use NeuronAI\Workflow\Executor\StepResult;
use NeuronAI\Workflow\Persistence\FilePersistence;
use NeuronAI\Workflow\Persistence\PersistenceInterface;
use PDO;
use PHPUnit\Framework\TestCase;

use function glob;
use function is_dir;
use function rmdir;
use function serialize;
use function strlen;
use function sys_get_temp_dir;
use function unlink;

use const DIRECTORY_SEPARATOR;

/**
 * The chat history is a runtime service injected into agent nodes — it must
 * never travel through the durable workflow state (per-step snapshots stay
 * O(1), and storage-backed histories carrying non-serializable resources like
 * PDO must not break workflow persistence).
 */
class AgentDurableHistoryTest extends TestCase
{
    public function test_sql_chat_history_works_with_durable_workflow_persistence(): void
    {
        // Regression: the state snapshot used to serialize the chat history object
        // itself, so any PDO-backed history crashed the first step save with
        // "Serialization of 'PDO' is not allowed".
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE chat_messages (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            thread_id TEXT, role TEXT, content TEXT, meta TEXT
        )');

        $searchTool = new SearchTool();

        $provider = new FakeAIProvider(
            new ToolCallMessage(null, [
                (clone $searchTool)->setCallId('call_1')->setInputs(['query' => 'PHP frameworks']),
            ]),
            new AssistantMessage('Here are the results.'),
        );

        $dir = sys_get_temp_dir() . '/neuron_sql_history_test';

        $agent = Agent::make(resumeToken: 'sql_history_test');
        $agent->setAiProvider($provider);
        $agent->addTool($searchTool);
        $agent->setChatHistory(new SQLChatHistory('thread-1', $pdo, table: 'chat_messages'));
        $agent->setPersistence(new FilePersistence($dir));

        $message = $agent->chat(new UserMessage('Search for PHP frameworks'))->getMessage();

        $this->assertSame('Here are the results.', $message->getContent());

        $this->removeDirectory($dir);
    }

    public function test_step_snapshots_do_not_carry_the_conversation(): void
    {
        // Persist every step through a recording proxy and assert the serialized
        // node-step snapshots stay flat instead of growing with the conversation.
        $searchTool = new SearchTool();

        $rounds = 4;
        $responses = [];
        for ($i = 0; $i < $rounds; $i++) {
            $responses[] = new ToolCallMessage(null, [
                (clone $searchTool)->setCallId('call_' . $i)->setInputs(['query' => 'q' . $i]),
            ]);
        }
        $responses[] = new AssistantMessage('Done.');

        $provider = new FakeAIProvider(...$responses);

        $recorder = new class () implements PersistenceInterface {
            /** @var array<string, int> */
            public array $blobSizes = [];
            /** @var array<string, array<string, StepResult>> */
            protected array $storage = [];

            public function save(string $workflowId, string $stepId, StepResult $result): void
            {
                $this->blobSizes[$stepId] = strlen(serialize($result));
                $this->storage[$workflowId][$stepId] = $result;
            }

            public function load(string $workflowId, string $stepId): ?StepResult
            {
                return $this->storage[$workflowId][$stepId] ?? null;
            }

            public function delete(string $workflowId): void
            {
                unset($this->storage[$workflowId]);
            }
        };

        $agent = Agent::make(resumeToken: 'snapshot_size_test');
        $agent->setAiProvider($provider);
        $agent->addTool($searchTool);
        $agent->setPersistence($recorder);

        $agent->chat(new UserMessage('Loop'))->getMessage();

        $first = $recorder->blobSizes[ChatNode::class . '-0'];
        $last = $recorder->blobSizes[ToolNode::class . '-' . (2 * $rounds - 1)];

        // Snapshots used to embed the whole conversation (two copies), growing
        // linearly per step. Now they carry only events + scalar state: the last
        // step of the loop must stay in the same ballpark as the first.
        $this->assertLessThan(
            $first * 2,
            $last,
            "Node-step snapshots grow with the conversation: first={$first}B, last={$last}B"
        );
    }

    public function test_crash_replay_does_not_duplicate_history_writes(): void
    {
        // History writes are durable memos: when a node crashes after writing to
        // the live history but before its step commits, the replay recalls the
        // 'history.*' memos and skips the writes instead of re-adding them.
        $workflowId = 'history_memo_replay_test';
        $persistence = new \NeuronAI\Workflow\Persistence\InMemoryPersistence();
        $stepId = ChatNode::class . '-0';

        $provider = new FakeAIProvider(new AssistantMessage('Hello back!'));

        $chatHistory = new \NeuronAI\Chat\History\InMemoryChatHistory();

        $event = new \NeuronAI\Agent\Events\AIInferenceEvent('Be helpful', []);
        $event->setMessages(new UserMessage('Hi'));

        // Run 1: the node executes fully (all memos committed) but its step is
        // never recorded — simulating a crash right before the step boundary.
        $engine1 = new \NeuronAI\Workflow\Executor\LocalStepEngine($persistence);
        $engine1->prepareExecution($workflowId);
        $state1 = new \NeuronAI\Agent\AgentState();
        $node1 = new ChatNode($provider, $chatHistory);
        $node1->setWorkflowContext($state1, $event, null, false, new \NeuronAI\Workflow\Executor\StepMemoizer($engine1, $stepId));
        $node1($event, $state1);

        $this->assertCount(2, $chatHistory->getMessages());

        // Recovery: fresh engine, same persistence, same durable history. The
        // node re-runs; the history writes and the inference are all recalled.
        $engine2 = new \NeuronAI\Workflow\Executor\LocalStepEngine($persistence);
        $engine2->prepareExecution($workflowId);
        $state2 = new \NeuronAI\Agent\AgentState();
        $node2 = new ChatNode($provider, $chatHistory);
        $node2->setWorkflowContext($state2, $event, null, false, new \NeuronAI\Workflow\Executor\StepMemoizer($engine2, $stepId));
        $node2($event, $state2);

        $messages = $chatHistory->getMessages();
        $this->assertCount(2, $messages, 'Replayed history writes must be skipped, not duplicated');
        $this->assertSame('Hi', $messages[0]->getContent());
        $this->assertSame('Hello back!', $messages[1]->getContent());
        $this->assertSame(1, $provider->getCallCount());
    }

    public function test_resume_with_sql_history_across_agent_instances(): void
    {
        // Interrupt (tool approval) in one process, resume from another agent
        // instance bound to the same durable thread: history + workflow
        // persistence are together sufficient to resume.
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE chat_messages (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            thread_id TEXT, role TEXT, content TEXT, meta TEXT
        )');

        $searchTool = new SearchTool();

        $provider = new FakeAIProvider(
            new ToolCallMessage(null, [
                (clone $searchTool)->setCallId('call_1')->setInputs(['query' => 'PHP frameworks']),
            ]),
            new AssistantMessage('Search results ready.'),
        );

        $dir = sys_get_temp_dir() . '/neuron_sql_resume_test';
        $workflowId = 'sql_resume_test';

        $agent1 = Agent::make(resumeToken: $workflowId);
        $agent1->setAiProvider($provider);
        $agent1->addTool($searchTool);
        $agent1->setChatHistory(new SQLChatHistory('thread-1', $pdo, table: 'chat_messages'));
        $agent1->setPersistence(new FilePersistence($dir));
        $agent1->addMiddleware(ToolNode::class, new ToolApproval([SearchTool::class]));

        $handler1 = $agent1->chat(new UserMessage('Search for PHP frameworks'));
        $handler1->run();

        $this->assertTrue($handler1->interrupted());

        // The suspended ToolCallMessage was stamped and persisted on the thread.
        $tail = $agent1->getChatHistory()->getLastMessage();
        $this->assertInstanceOf(ToolCallMessage::class, $tail);
        $this->assertSame($workflowId, $tail->getResumeToken());

        // Fresh agent instance on the same thread: the resume token is adopted
        // from the history tail (ADR 0005), no explicit token needed.
        $agent2 = Agent::make();
        $agent2->setAiProvider($provider);
        $agent2->addTool($searchTool);
        $agent2->setChatHistory(new SQLChatHistory('thread-1', $pdo, table: 'chat_messages'));
        $agent2->setPersistence(new FilePersistence($dir));
        $agent2->addMiddleware(ToolNode::class, new ToolApproval([SearchTool::class]));

        $message = $agent2->chat(payload: ['call_1' => 'approve'])->getMessage();

        $this->assertSame('Search results ready.', $message->getContent());

        $this->removeDirectory($dir);
    }

    protected function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = glob($dir . DIRECTORY_SEPARATOR . '*') ?: [];
        foreach ($files as $file) {
            unlink($file);
        }

        rmdir($dir);
    }
}
