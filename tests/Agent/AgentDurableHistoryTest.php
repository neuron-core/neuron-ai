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
 * The chat history never travels through the durable workflow state: snapshots
 * stay O(1) and PDO-backed histories never meet the serializer.
 */
class AgentDurableHistoryTest extends TestCase
{
    public function test_sql_chat_history_works_with_durable_workflow_persistence(): void
    {
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

        $this->assertLessThan(
            $first * 2,
            $last,
            "Node-step snapshots grow with the conversation: first={$first}B, last={$last}B"
        );
    }

    public function test_final_state_carries_the_current_cycle_steps_across_interrupt(): void
    {
        $workflowId = 'steps_cycle_test';
        $persistence = new \NeuronAI\Workflow\Persistence\InMemoryPersistence();
        $executor = new \NeuronAI\Workflow\Executor\WorkflowExecutor(new \NeuronAI\Workflow\Executor\LocalStepEngine($persistence));
        $history = new \NeuronAI\Chat\History\InMemoryChatHistory();

        $searchTool = new SearchTool();

        $provider = new FakeAIProvider(
            new ToolCallMessage(null, [
                (clone $searchTool)->setCallId('call_1')->setInputs(['query' => 'PHP frameworks']),
            ]),
            new AssistantMessage('Here are the results.'),
        );

        $agent1 = Agent::make(resumeToken: $workflowId);
        $agent1->setChatHistory($history);
        $agent1->setAiProvider($provider);
        $agent1->addTool($searchTool);
        $agent1->setExecutor($executor);
        $agent1->addMiddleware(ToolNode::class, new ToolApproval([SearchTool::class]));

        $state1 = $agent1->chat(new UserMessage('Search for PHP frameworks'))->run();

        $this->assertTrue($state1->isInterrupted());
        $steps1 = $state1->getSteps();
        $this->assertCount(1, $steps1, 'The interrupted cycle processed only the inbound user message');
        $this->assertSame('Search for PHP frameworks', $steps1[0]->getContent());

        $agent2 = Agent::make(resumeToken: $workflowId);
        $agent2->setChatHistory($history);
        $agent2->setAiProvider($provider);
        $agent2->addTool($searchTool);
        $agent2->setExecutor($executor);
        $agent2->addMiddleware(ToolNode::class, new ToolApproval([SearchTool::class]));

        $state2 = $agent2->chat(payload: ['call_1' => 'approve'])->run();

        $steps2 = $state2->getSteps();
        $this->assertCount(3, $steps2, 'The resume cycle reports its own messages: tool call, tool result, final response');
        $this->assertInstanceOf(ToolCallMessage::class, $steps2[0]);
        $this->assertSame('Here are the results.', $steps2[2]->getContent());
    }

    public function test_crash_replay_does_not_duplicate_history_writes(): void
    {
        $workflowId = 'history_memo_replay_test';
        $persistence = new \NeuronAI\Workflow\Persistence\InMemoryPersistence();
        $stepId = ChatNode::class . '-0';

        $provider = new FakeAIProvider(new AssistantMessage('Hello back!'));

        $chatHistory = new \NeuronAI\Chat\History\InMemoryChatHistory();

        $event = new \NeuronAI\Agent\Events\AIInferenceEvent('Be helpful', []);
        $event->setMessages(new UserMessage('Hi'));

        // Run 1: all memos commit but the step is never recorded (crash before the step boundary).
        $engine1 = new \NeuronAI\Workflow\Executor\LocalStepEngine($persistence);
        $engine1->prepareExecution($workflowId);
        $state1 = new \NeuronAI\Agent\AgentState();
        $node1 = new ChatNode($provider, $chatHistory);
        $node1->setWorkflowContext($state1, $event, null, false, new \NeuronAI\Workflow\Executor\StepMemoizer($engine1, $stepId));
        $node1($event, $state1);

        $this->assertCount(2, $chatHistory->getMessages());

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

        $tail = $agent1->getChatHistory()->getLastMessage();
        $this->assertInstanceOf(ToolCallMessage::class, $tail);
        $this->assertSame($workflowId, $tail->getResumeToken());

        // Fresh agent on the same thread: the resume token is adopted from the history tail (ADR 0005).
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
