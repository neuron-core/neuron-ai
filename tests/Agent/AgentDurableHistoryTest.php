<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Agent;

use NeuronAI\Agent\Agent;
use NeuronAI\Agent\Nodes\ChatNode;
use NeuronAI\Agent\Nodes\ToolNode;
use NeuronAI\Chat\History\SQLChatHistory;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Tests\Agent\Stub\SearchTool;
use NeuronAI\Tests\Support\ExecutorTestHelpers;
use NeuronAI\Tools\ToolCall;
use NeuronAI\Workflow\Interrupt\ResumeInput;
use NeuronAI\Workflow\NodeContext;
use NeuronAI\Workflow\Persistence\FilePersistence;
use NeuronAI\Workflow\Persistence\InMemoryPersistence;
use NeuronAI\Workflow\Persistence\PhpSerializer;
use PDO;
use PHPUnit\Framework\TestCase;
use function glob;
use function is_dir;
use function rmdir;
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
    use ExecutorTestHelpers;

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
                ToolCall::make($searchTool->getName(), 'call_1', ['query' => 'PHP frameworks']),
            ]),
            new AssistantMessage('Here are the results.'),
        );

        $dir = sys_get_temp_dir() . '/neuron_sql_history_test';

        $agent = Agent::make();
        $agent->setAiProvider($provider);
        $agent->addTool($searchTool);
        $agent->setChatHistory(new SQLChatHistory($pdo, 'thread-1', table: 'chat_messages'));
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
                ToolCall::make($searchTool->getName(), 'call_' . $i, ['query' => 'q' . $i]),
            ]);
        }
        $responses[] = new AssistantMessage('Done.');

        $provider = new FakeAIProvider(...$responses);

        $recorder = new class () extends InMemoryPersistence {
            /** @var array<string, int> */
            public array $blobSizes = [];

            public function writeIfUnchanged(
                string $partition,
                string $conditionKey,
                string $expectedValue,
                array $records,
            ): bool {
                foreach ($records as $key => $value) {
                    $this->blobSizes[$key] = strlen($value);
                }

                return parent::writeIfUnchanged(
                    $partition,
                    $conditionKey,
                    $expectedValue,
                    $records,
                );
            }
        };

        $agent = Agent::make(workflowId: 'snapshot_size_test');
        $agent->setAiProvider($provider);
        $agent->addTool($searchTool);
        $agent->setPersistence($recorder);

        $agent->chat(new UserMessage('Loop'))->getMessage();

        $first = $recorder->blobSizes[$this->stepKey($agent, ChatNode::class . '-1')];
        $last = $recorder->blobSizes[$this->stepKey($agent, ToolNode::class . '-' . (2 * $rounds))];

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
        $history = new \NeuronAI\Chat\History\InMemoryChatHistory($workflowId);

        $searchTool = new SearchTool();
        // Attach-time approval config: the flag rides on the
        // instance, so the clone in the tool call message carries it too.
        $searchTool->requireApproval();

        $provider = new FakeAIProvider(
            new ToolCallMessage(null, [
                ToolCall::make($searchTool->getName(), 'call_1', ['query' => 'PHP frameworks']),
            ]),
            new AssistantMessage('Here are the results.'),
        );

        $agent1 = Agent::make(workflowId: $workflowId);
        $agent1->setChatHistory($history);
        $agent1->setAiProvider($provider);
        $agent1->addTool($searchTool);
        $agent1->setPersistence($persistence);

        $state1 = $agent1->chat(new UserMessage('Search for PHP frameworks'));

        $this->assertTrue($state1->isInterrupted());
        $steps1 = $state1->getSteps();
        $this->assertCount(2, $steps1, 'The interrupted cycle carries the inbound user message and the pending tool call');
        $this->assertSame('Search for PHP frameworks', $steps1[0]->getContent());
        $this->assertInstanceOf(ToolCallMessage::class, $steps1[1]);

        $agent2 = Agent::make(workflowId: $workflowId);
        $agent2->setChatHistory($history);
        $agent2->setAiProvider($provider);
        $agent2->addTool($searchTool);
        $agent2->setPersistence($persistence);

        $state2 = $agent2->resume([ResumeInput::event(1, ['call_1' => 'approve'])]);

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
        $state1 = new \NeuronAI\Agent\AgentState();
        $node1 = new ChatNode($provider, $chatHistory);
        $node1->setWorkflowContext(new NodeContext($state1, $event, null, false, new \NeuronAI\Workflow\Executor\StepMemoizer($persistence, new PhpSerializer(), $workflowId, $stepId)));
        $node1($event, $state1);

        $this->assertCount(2, $chatHistory->getMessages());

        $state2 = new \NeuronAI\Agent\AgentState();
        $node2 = new ChatNode($provider, $chatHistory);
        $node2->setWorkflowContext(new NodeContext($state2, $event, null, false, new \NeuronAI\Workflow\Executor\StepMemoizer($persistence, new PhpSerializer(), $workflowId, $stepId)));
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
        // Attach-time approval config: the flag rides on the
        // instance, so the clone in the tool call message carries it too.
        $searchTool->requireApproval();

        $provider = new FakeAIProvider(
            new ToolCallMessage(null, [
                ToolCall::make($searchTool->getName(), 'call_1', ['query' => 'PHP frameworks']),
            ]),
            new AssistantMessage('Search results ready.'),
        );

        $dir = sys_get_temp_dir() . '/neuron_sql_resume_test';

        $agent1 = Agent::make();
        $agent1->setAiProvider($provider);
        $agent1->addTool($searchTool);
        $agent1->setChatHistory(new SQLChatHistory($pdo, 'thread-1', table: 'chat_messages'));
        $agent1->setPersistence(new FilePersistence($dir));

        $state1 = $agent1->chat(new UserMessage('Search for PHP frameworks'));

        $this->assertTrue($state1->isInterrupted());

        $tail = $agent1->getChatHistory()->getLastMessage();
        $this->assertInstanceOf(ToolCallMessage::class, $tail);

        // Fresh agent on the same thread: the thread IS the workflow ID —
        // no other handle is passed.
        $agent2 = Agent::make();
        $agent2->setAiProvider($provider);
        $agent2->addTool($searchTool);
        $agent2->setChatHistory(new SQLChatHistory($pdo, 'thread-1', table: 'chat_messages'));
        $agent2->setPersistence(new FilePersistence($dir));

        $message = $agent2->resume([ResumeInput::event(1, ['call_1' => 'approve'])])->getMessage();

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
