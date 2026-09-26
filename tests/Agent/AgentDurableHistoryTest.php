<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Agent;

use NeuronAI\Tests\Support\AgentResourcesFactory;
use NeuronAI\Agent\Agent;
use NeuronAI\Agent\AgentState;
use NeuronAI\Agent\InferenceRequest;
use NeuronAI\Agent\Nodes\ChatNode;
use NeuronAI\Agent\Nodes\ToolNode;
use NeuronAI\Chat\History\ChatHistory;
use NeuronAI\Chat\History\InMemoryMessageStore;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Tests\Agent\Stub\SearchTool;
use NeuronAI\Tests\Chat\History\Stub\SqliteMessageStore;
use NeuronAI\Tests\Support\ExecutorTestHelpers;
use NeuronAI\Tests\Support\FileSystemSandbox;
use NeuronAI\Tools\ToolCall;
use NeuronAI\Workflow\NodeContext;
use NeuronAI\Workflow\Persistence\FilePersistence;
use NeuronAI\Workflow\Persistence\InMemoryPersistence;
use PHPUnit\Framework\TestCase;

use function array_keys;
use function array_map;
use function iterator_to_array;
use function str_contains;
use function strlen;

/**
 * The chat history never travels through the durable workflow state: snapshots
 * stay O(1) and PDO-backed histories never meet the serializer.
 */
class AgentDurableHistoryTest extends TestCase
{
    use ExecutorTestHelpers;
    use FileSystemSandbox;

    protected ?string $persistenceDirectory = null;

    protected function tearDown(): void
    {
        if ($this->persistenceDirectory !== null) {
            $this->removeSandbox($this->persistenceDirectory);
        }
    }

    public function test_sql_chat_history_works_with_durable_workflow_persistence(): void
    {
        $searchTool = new SearchTool();

        $provider = new FakeAIProvider(
            new ToolCallMessage(null, [
                ToolCall::make($searchTool->getName(), 'call_1', ['query' => 'PHP frameworks']),
            ]),
            new AssistantMessage('Here are the results.'),
        );

        $dir = $this->persistenceDirectory = $this->createSandbox('neuron_sql_history');

        $agent = Agent::make(workflowId: 'thread-1');
        $agent->setAiProvider($provider);
        $agent->addTool($searchTool);
        $agent->setMessageStore(new SqliteMessageStore());
        $agent->setPersistence(new FilePersistence($dir));

        $message = $agent->chat(new UserMessage('Search for PHP frameworks'))->getMessage();

        $this->assertSame('Here are the results.', $message->getContent());
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

        $state = $agent->chat(new UserMessage('Loop'));

        $first = $recorder->blobSizes[$state->getRunId() . '/' . ChatNode::class . '-1'];
        $last = $recorder->blobSizes[$state->getRunId() . '/' . ToolNode::class . '-' . (2 * $rounds)];

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
        $messages = new InMemoryMessageStore();

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
        $agent1->setMessageStore($messages);
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
        $agent2->setMessageStore($messages);
        $agent2->setAiProvider($provider);
        $agent2->addTool($searchTool);
        $agent2->setPersistence($persistence);

        $state2 = $agent2->run(\NeuronAI\Workflow\Executor\ExecutionRequest::resume(['call_1' => 'approve']));

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

        $messages = new InMemoryMessageStore();

        $state = new AgentState();
        $state->request = new InferenceRequest('Be helpful');
        $event = new \NeuronAI\Agent\Events\AIInferenceEvent();
        $state->request->messages = [new UserMessage('Hi')];

        // Run 1: all memos commit but the step is never recorded (crash before the step boundary).
        $state1 = new \NeuronAI\Agent\AgentState();
        $state1->request = clone $state->request;
        $node1 = new ChatNode();
        $node1->setWorkflowContext(new NodeContext(null, false, \NeuronAI\Tests\Support\WorkflowTestStore::memoizer($persistence, $workflowId, $stepId)));
        $this->assertSame([], iterator_to_array($node1($event, $state1, AgentResourcesFactory::make([], new ChatHistory($messages, $workflowId), $provider))));

        $this->assertCount(2, $messages->loadActive($workflowId));

        $state2 = new \NeuronAI\Agent\AgentState();
        $state2->request = clone $state->request;
        $node2 = new ChatNode();
        $node2->setWorkflowContext(new NodeContext(null, false, \NeuronAI\Tests\Support\WorkflowTestStore::memoizer($persistence, $workflowId, $stepId)));
        $this->assertSame([], iterator_to_array($node2($event, $state2, AgentResourcesFactory::make([], new ChatHistory($messages, $workflowId), $provider))));

        $stored = $messages->loadActive($workflowId);
        $this->assertCount(2, $stored, 'Replayed history writes must be skipped, not duplicated');
        $this->assertSame('Hi', $stored[0]->getContent());
        $this->assertSame('Hello back!', $stored[1]->getContent());
        $this->assertSame(1, $provider->getCallCount());
    }

    public function test_replaying_history_writes_whose_memos_were_lost_does_not_duplicate_them(): void
    {
        $workflowId = 'history_write_replay_test';
        $stepId = ChatNode::class . '-0';
        $messages = new InMemoryMessageStore();
        $provider = new FakeAIProvider(new AssistantMessage('Hello back!'));
        // Drops the history memos, as a crash between a history write and its memo commit would.
        $persistence = new class () extends InMemoryPersistence {
            public function writeIfUnchanged(string $partition, string $conditionKey, string $expectedValue, array $records): bool
            {
                foreach (array_keys($records) as $key) {
                    if (str_contains($key, '::history.')) {
                        unset($records[$key]);
                    }
                }

                return parent::writeIfUnchanged($partition, $conditionKey, $expectedValue, $records);
            }
        };
        $event = new \NeuronAI\Agent\Events\AIInferenceEvent();
        // The replayed step restores its inbound message, identity included, from the checkpoint.
        $inbound = new UserMessage('Hi');

        foreach ([1, 2] as $attempt) {
            $state = new AgentState();
            $state->request = new InferenceRequest('Be helpful');
            $state->request->messages = [clone $inbound];
            $node = new ChatNode();
            $node->setWorkflowContext(new NodeContext(null, false, \NeuronAI\Tests\Support\WorkflowTestStore::memoizer($persistence, $workflowId, $stepId)));
            iterator_to_array($node($event, $state, AgentResourcesFactory::make([], new ChatHistory($messages, $workflowId), $provider)));
        }

        // The replay recalls the memoized response, so both writes repeat the same messages.
        $this->assertSame(1, $provider->getCallCount());
        $this->assertSame(['Hi', 'Hello back!'], array_map(
            fn (Message $message): ?string => $message->getContent(),
            $messages->loadAll($workflowId)
        ));
    }

    public function test_resume_with_sql_history_across_agent_instances(): void
    {
        $messages = new SqliteMessageStore();

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

        $dir = $this->persistenceDirectory = $this->createSandbox('neuron_sql_resume');

        $agent1 = Agent::make(workflowId: 'thread-1');
        $agent1->setAiProvider($provider);
        $agent1->addTool($searchTool);
        $agent1->setMessageStore($messages);
        $agent1->setPersistence(new FilePersistence($dir));

        $state1 = $agent1->chat(new UserMessage('Search for PHP frameworks'));

        $this->assertTrue($state1->isInterrupted());

        $tail = $agent1->getChatHistory()->getLastMessage();
        $this->assertInstanceOf(ToolCallMessage::class, $tail);

        // Fresh agent on the same thread: the thread IS the workflow ID —
        // no other handle is passed.
        $agent2 = Agent::make(workflowId: 'thread-1');
        $agent2->setAiProvider($provider);
        $agent2->addTool($searchTool);
        $agent2->setMessageStore($messages);
        $agent2->setPersistence(new FilePersistence($dir));

        $message = $agent2->run(\NeuronAI\Workflow\Executor\ExecutionRequest::resume(['call_1' => 'approve']))->getMessage();

        $this->assertSame('Search results ready.', $message->getContent());
    }
}
