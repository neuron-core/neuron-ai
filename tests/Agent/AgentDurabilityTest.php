<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Agent;

use NeuronAI\Tools\ToolCall;
use NeuronAI\Workflow\NodeContext;
use NeuronAI\Agent\Agent;
use NeuronAI\Agent\AgentState;
use NeuronAI\Chat\History\InMemoryChatHistory;
use NeuronAI\Agent\Events\AIInferenceEvent;
use NeuronAI\Agent\Nodes\ChatNode;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Tests\Agent\Stub\ClosureDependencyTool;
use NeuronAI\Tests\Agent\Stub\CrashSearchTool;
use NeuronAI\Tests\Agent\Stub\SearchTool;
use NeuronAI\Tools\Tool;
use NeuronAI\Workflow\Executor\StepMemoizer;
use NeuronAI\Workflow\Interrupt\ApprovalRequest;
use NeuronAI\Workflow\Persistence\FilePersistence;
use NeuronAI\Workflow\Persistence\InMemoryPersistence;
use NeuronAI\Workflow\Persistence\PhpSerializer;
use NeuronAI\Workflow\Resume\ResumeInput;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function glob;
use function is_dir;
use function rmdir;
use function sys_get_temp_dir;
use function unlink;

use const DIRECTORY_SEPARATOR;

class AgentDurabilityTest extends TestCase
{
    public function test_crash_recovery_during_tool_execution(): void
    {
        $workflowId = 'agent_recovery_test';
        $persistence = new InMemoryPersistence();
        $history = new InMemoryChatHistory($workflowId);

        $searchTool = new CrashSearchTool();

        $provider = new FakeAIProvider(
            new ToolCallMessage(null, [
                ToolCall::make($searchTool->getName(), 'call_1', ['query' => 'PHP frameworks']),
            ]),
            new AssistantMessage('Based on my search, here are the top PHP frameworks...'),
        );

        // Run 1: ChatNode completes, tool crashes
        $agent1 = Agent::make(workflowId: $workflowId);
        $agent1->setChatHistory($history);
        $agent1->setAiProvider($provider);
        $agent1->addTool($searchTool);
        $agent1->setPersistence($persistence);

        try {
            $agent1->chat(new UserMessage('Search for PHP frameworks'))->getMessage();
            $this->fail('Expected RuntimeException was not thrown');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Simulated crash', $e->getMessage());
        }

        $this->assertSame(1, $provider->getCallCount());
        $this->assertSame(1, $searchTool->getCallCount());

        // Revive with the same workflow ID: ChatNode:0 memoized, the tool retries.
        $agent2 = Agent::make(workflowId: $workflowId);
        $agent2->setChatHistory($history);
        $agent2->setAiProvider($provider);
        $agent2->addTool($searchTool);
        $agent2->setPersistence($persistence);

        $message = $agent2->resume()->getMessage();

        $this->assertSame('Based on my search, here are the top PHP frameworks...', $message->getContent());
        $this->assertSame(2, $provider->getCallCount());
        $this->assertSame(2, $searchTool->getCallCount());
    }

    public function test_chat_node_inference_memoized_across_crash_recovery(): void
    {
        $chatHistory = new InMemoryChatHistory();
        // Crash window under test: ChatNode's inference succeeds and is memoized
        // mid-node, then the node crashes BEFORE its step completes. On recovery with
        // a fresh step engine + same persistence, the node re-executes but the inference
        // must NOT be billed again.
        $workflowId = 'agent_chatnode_memo_test';
        $persistence = new InMemoryPersistence();
        $stepId = ChatNode::class . '-0';

        $provider = new FakeAIProvider(new AssistantMessage('Hello back!'));

        $event = new AIInferenceEvent('Be helpful', []);
        $event->setMessages(new UserMessage('Hi'));

        // Run 1: invoke the node directly with a durable memoizer bound to the step.
        // The inference memo is persisted on the first run. (We never record the node
        // step itself as completed — simulating a crash right after memoize().)

        $state1 = new AgentState();
        $state1->set('__runId', $workflowId);
        $node1 = new ChatNode($provider, $chatHistory);
        $node1->setWorkflowContext(new NodeContext($state1, $event, null, false, new StepMemoizer($persistence, new PhpSerializer(), $workflowId, $stepId)));
        $node1($event, $state1);

        $this->assertSame(1, $provider->getCallCount());

        // Recovery: brand-new engine, same persistence (simulates a process restart).

        $state2 = new AgentState();
        $state2->set('__runId', $workflowId);
        $node2 = new ChatNode($provider, $chatHistory);
        $node2->setWorkflowContext(new NodeContext($state2, $event, null, false, new StepMemoizer($persistence, new PhpSerializer(), $workflowId, $stepId)));
        $node2($event, $state2);

        $this->assertSame(1, $provider->getCallCount(), 'Inference must not be re-billed on recovery');
    }

    public function test_interrupt_resume_with_approval_gate(): void
    {
        $workflowId = 'agent_approval_test';
        $persistence = new InMemoryPersistence();
        $history = new InMemoryChatHistory($workflowId);

        $searchTool = new SearchTool();
        // Attach-time approval config: the flag rides on the
        // instance, so the clone in the tool call message carries it too.
        $searchTool->requireApproval();

        $provider = new FakeAIProvider(
            new ToolCallMessage(null, [
                ToolCall::make($searchTool->getName(), 'call_1', ['query' => 'PHP frameworks']),
            ]),
            new AssistantMessage('Here are the search results...'),
        );

        // Run 1: ChatNode completes, the approval gate pauses ToolNode before the tool executes.
        $agent1 = Agent::make(workflowId: $workflowId);
        $agent1->setChatHistory($history);
        $agent1->setAiProvider($provider);
        $agent1->addTool($searchTool);
        $agent1->setPersistence($persistence);

        $state1 = $agent1->chat(new UserMessage('Search for PHP frameworks'));

        $this->assertTrue($state1->isInterrupted());
        $this->assertInstanceOf(ApprovalRequest::class, $state1->getInterruptRequest());
        // Only the ChatNode inference ran; the tool never executed (no second inference).
        $this->assertSame(1, $provider->getCallCount());

        // Resume: deliver the approval payload (call_1 approved). Same runId →
        // ChatNode:0 memoized, ToolNode:1 resumes and runs the tool.
        $agent2 = Agent::make(workflowId: $workflowId);
        $agent2->setChatHistory($history);
        $agent2->setAiProvider($provider);
        $agent2->addTool($searchTool);
        $agent2->setPersistence($persistence);

        $message = $agent2->resume([ResumeInput::event(1, ['call_1' => 'approve'])])->getMessage();

        $this->assertSame('Here are the search results...', $message->getContent());
        $this->assertSame(2, $provider->getCallCount());
    }

    public function test_chat_no_tools_step_cleanup_after_completion(): void
    {
        $workflowId = 'agent_cleanup_test';
        $persistence = new InMemoryPersistence();

        $provider = new FakeAIProvider(
            new AssistantMessage('Hello!'),
        );

        $agent = Agent::make(workflowId: $workflowId);
        $agent->setAiProvider($provider);
        $agent->setPersistence($persistence);

        $message = $agent->chat(new UserMessage('Hi'))->getMessage();

        $this->assertSame('Hello!', $message->getContent());
        $this->assertSame(1, $provider->getCallCount());

        // Steps should be cleaned up after successful completion
        $this->assertNull($persistence->get($workflowId, \NeuronAI\Agent\Nodes\ChatNode::class . '-0'));
    }

    public function test_approval_gate_rejects_tool(): void
    {
        $workflowId = 'agent_rejection_test';
        $persistence = new InMemoryPersistence();
        $history = new InMemoryChatHistory($workflowId);

        $searchTool = new SearchTool();
        // Attach-time approval config: the flag rides on the
        // instance, so the clone in the tool call message carries it too.
        $searchTool->requireApproval();

        $provider = new FakeAIProvider(
            new ToolCallMessage(null, [
                ToolCall::make($searchTool->getName(), 'call_1', ['query' => 'PHP frameworks']),
            ]),
            new AssistantMessage('I see the search was rejected. Is there anything else I can help with?'),
        );

        $agent1 = Agent::make(workflowId: $workflowId);
        $agent1->setChatHistory($history);
        $agent1->setAiProvider($provider);
        $agent1->addTool($searchTool);
        $agent1->setPersistence($persistence);

        $state1 = $agent1->chat(new UserMessage('Search for PHP frameworks'));

        $this->assertTrue($state1->isInterrupted());
        $request = $state1->getInterruptRequest();
        $this->assertInstanceOf(ApprovalRequest::class, $request);

        // Resume with rejection: the tool is NOT executed; its rejection message
        // is fed back as the tool result and reaches the next inference.
        $agent2 = Agent::make(workflowId: $workflowId);
        $agent2->setChatHistory($history);
        $agent2->setAiProvider($provider);
        $agent2->addTool($searchTool);
        $agent2->setPersistence($persistence);

        $message = $agent2->resume([ResumeInput::event(1, [
            'call_1' => ['reject', 'Do not search the web.'],
        ])])->getMessage();

        $this->assertSame(
            'I see the search was rejected. Is there anything else I can help with?',
            $message->getContent()
        );
    }

    public function test_successful_tool_call_with_step_engine(): void
    {
        $workflowId = 'agent_tool_success_test';
        $persistence = new InMemoryPersistence();

        $searchTool = new SearchTool();

        $provider = new FakeAIProvider(
            new ToolCallMessage(null, [
                ToolCall::make($searchTool->getName(), 'call_1', ['query' => 'PHP frameworks']),
            ]),
            new AssistantMessage('Based on the search results, here are the top PHP frameworks...'),
        );

        $agent = Agent::make(workflowId: $workflowId);
        $agent->setAiProvider($provider);
        $agent->addTool($searchTool);
        $agent->setPersistence($persistence);

        $message = $agent->chat(new UserMessage('Search for PHP frameworks'))->getMessage();

        $this->assertSame('Based on the search results, here are the top PHP frameworks...', $message->getContent());
        $this->assertSame(2, $provider->getCallCount());

        // Steps should be cleaned up after successful completion
        $this->assertNull($persistence->get($workflowId, ChatNode::class . '-0'));
    }

    public function test_interrupt_resume_with_file_persistence(): void
    {
        $workflowId = 'agent_file_interrupt_test';
        $dir = sys_get_temp_dir() . '/neuron_test_' . $workflowId;

        $provider = new FakeAIProvider(
            new AssistantMessage('Hello!'),
        );

        $persistence = new FilePersistence($dir);

        $agent = Agent::make(workflowId: $workflowId);
        $agent->setAiProvider($provider);
        $agent->setPersistence($persistence);

        $message = $agent->chat(new UserMessage('Hi'))->getMessage();
        $this->assertSame('Hello!', $message->getContent());

        // After successful completion, persistence file should be deleted
        $this->assertFileDoesNotExist($dir . '/' . $workflowId . '.store');

        $this->removeDirectory($dir);
    }

    public function test_tool_call_with_file_persistence_and_unserializable_tool_dependency(): void
    {
        // Regression: with a durable persistence backend every step (and the inference
        // memo) is serialized. A tool holding a non-serializable dependency (PDO,
        // HTTP client, closure) must not break the run — Tool::__serialize() persists
        // only the call data.
        $workflowId = 'agent_file_unserializable_tool_test';
        $dir = sys_get_temp_dir() . '/neuron_test_' . $workflowId;

        $tool = new ClosureDependencyTool(fn (): string => '42');

        $provider = new FakeAIProvider(
            new ToolCallMessage(null, [
                ToolCall::make($tool->getName(), 'call_1'),
            ]),
            new AssistantMessage('There are 42 users in the database.'),
        );

        $agent = Agent::make(workflowId: $workflowId);
        $agent->setAiProvider($provider);
        $agent->addTool($tool);
        $agent->setPersistence(new FilePersistence($dir));

        $message = $agent->chat(new UserMessage('How many users in the database?'))->getMessage();

        $this->assertSame('There are 42 users in the database.', $message->getContent());
        $this->assertSame(2, $provider->getCallCount());

        $this->removeDirectory($dir);
    }

    public function test_crash_recovery_resolves_tools_from_live_registry(): void
    {
        $workflowId = 'agent_file_tool_recovery_test';
        $dir = sys_get_temp_dir() . '/neuron_test_' . $workflowId;
        $persistence = new FilePersistence($dir);
        $history = new InMemoryChatHistory($workflowId);

        $calls = 0;
        $tool = new ClosureDependencyTool(function () use (&$calls): string {
            $calls++;
            if ($calls === 1) {
                throw new RuntimeException('Simulated crash during tool execution');
            }

            return '42';
        });

        $provider = new FakeAIProvider(
            new ToolCallMessage(null, [
                ToolCall::make($tool->getName(), 'call_1'),
            ]),
            new AssistantMessage('There are 42 users in the database.'),
        );

        // Run 1: ChatNode completes (its step is serialized to disk — carrying only
        // ToolCall data, never the tool's closure), then the tool crashes.
        $agent1 = Agent::make(workflowId: $workflowId);
        $agent1->setChatHistory($history);
        $agent1->setAiProvider($provider);
        $agent1->addTool($tool);
        $agent1->setPersistence($persistence);

        try {
            $agent1->chat(new UserMessage('How many users in the database?'))->getMessage();
            $this->fail('Expected RuntimeException was not thrown');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Simulated crash', $e->getMessage());
        }

        // Revive in a new process: fresh engine, same file persistence. The recalled
        // ChatNode step carries ToolCall data only — ToolNode resolves the
        // calls against the live registry to execute with a working dependency.
        $agent2 = Agent::make(workflowId: $workflowId);
        $agent2->setChatHistory($history);
        $agent2->setAiProvider($provider);
        $agent2->addTool($tool);
        $agent2->setPersistence($persistence);

        $message = $agent2->resume()->getMessage();

        $this->assertSame('There are 42 users in the database.', $message->getContent());
        $this->assertSame(2, $calls);
        // The recalled inference was not re-billed: one call per distinct response.
        $this->assertSame(2, $provider->getCallCount());

        $this->removeDirectory($dir);
    }

    protected function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = glob($dir . DIRECTORY_SEPARATOR . '*') ?: [];
        foreach ($files as $file) {
            is_dir($file) ? $this->removeDirectory($file) : unlink($file);
        }

        rmdir($dir);
    }
}
