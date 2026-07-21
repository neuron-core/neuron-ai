<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Agent;

use NeuronAI\Agent\Agent;
use NeuronAI\Agent\AgentState;
use NeuronAI\Agent\Events\AIInferenceEvent;
use NeuronAI\Agent\Middleware\ToolApproval;
use NeuronAI\Agent\Nodes\ChatNode;
use NeuronAI\Agent\Nodes\ToolNode;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Tests\Agent\Tools\CrashSearchTool;
use NeuronAI\Tests\Agent\Tools\SearchTool;
use NeuronAI\Tools\Tool;
use NeuronAI\Workflow\Executor\LocalStepEngine;
use NeuronAI\Workflow\Executor\StepMemoizer;
use NeuronAI\Workflow\Executor\WorkflowExecutor;
use NeuronAI\Workflow\Interrupt\ApprovalRequest;
use NeuronAI\Workflow\Persistence\FilePersistence;
use NeuronAI\Workflow\Persistence\InMemoryPersistence;
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
    public function testCrashRecoveryDuringToolExecution(): void
    {
        $workflowId = 'agent_recovery_test';
        $persistence = new InMemoryPersistence();
        $executor = new WorkflowExecutor(new LocalStepEngine($persistence));

        $searchTool = new CrashSearchTool();

        $provider = new FakeAIProvider(
            new ToolCallMessage(null, [
                (clone $searchTool)->setCallId('call_1')->setInputs(['query' => 'PHP frameworks']),
            ]),
            new AssistantMessage('Based on my search, here are the top PHP frameworks...'),
        );

        // Run 1: ChatNode completes, tool crashes
        $agent1 = Agent::make(resumeToken: $workflowId);
        $agent1->setAiProvider($provider);
        $agent1->addTool($searchTool);
        $agent1->setExecutor($executor);

        try {
            $agent1->chat(new UserMessage('Search for PHP frameworks'))->getMessage();
            $this->fail('Expected RuntimeException was not thrown');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Simulated crash', $e->getMessage());
        }

        $this->assertSame(1, $provider->getCallCount());
        $this->assertSame(1, $searchTool->getCallCount());

        // Recovery: same workflowId, same step engine → ChatNode:0 memoized
        $agent2 = Agent::make(resumeToken: $workflowId);
        $agent2->setAiProvider($provider);
        $agent2->addTool($searchTool);
        $agent2->setExecutor($executor);

        $message = $agent2->chat(new UserMessage('Search for PHP frameworks'))->getMessage();

        $this->assertSame('Based on my search, here are the top PHP frameworks...', $message->getContent());
        $this->assertSame(2, $provider->getCallCount());
        $this->assertSame(2, $searchTool->getCallCount());
    }

    public function testChatNodeInferenceMemoizedAcrossCrashRecovery(): void
    {
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
        $engine1 = new LocalStepEngine($persistence);
        $engine1->prepareExecution($workflowId);

        $state1 = new AgentState();
        $state1->set('__workflowId', $workflowId);
        $node1 = new ChatNode($provider);
        $node1->setWorkflowContext($state1, $event, null, new StepMemoizer($engine1, $stepId));
        $node1($event, $state1);

        $this->assertSame(1, $provider->getCallCount());

        // Recovery: brand-new engine, same persistence (simulates a process restart).
        $engine2 = new LocalStepEngine($persistence);
        $engine2->prepareExecution($workflowId);

        $state2 = new AgentState();
        $state2->set('__workflowId', $workflowId);
        $node2 = new ChatNode($provider);
        $node2->setWorkflowContext($state2, $event, null, new StepMemoizer($engine2, $stepId));
        $node2($event, $state2);

        $this->assertSame(1, $provider->getCallCount(), 'Inference must not be re-billed on recovery');
    }

    public function testInterruptResumeWithToolApproval(): void
    {
        $workflowId = 'agent_approval_test';
        $persistence = new InMemoryPersistence();
        $executor = new WorkflowExecutor(new LocalStepEngine($persistence));

        $searchTool = new SearchTool();

        $provider = new FakeAIProvider(
            new ToolCallMessage(null, [
                (clone $searchTool)->setCallId('call_1')->setInputs(['query' => 'PHP frameworks']),
            ]),
            new AssistantMessage('Here are the search results...'),
        );

        // Run 1: ChatNode completes, ToolApproval pauses BEFORE the tool executes.
        $agent1 = Agent::make(resumeToken: $workflowId);
        $agent1->setAiProvider($provider);
        $agent1->addTool($searchTool);
        $agent1->addMiddleware(ToolNode::class, new ToolApproval());
        $agent1->setExecutor($executor);

        $handler1 = $agent1->chat(new UserMessage('Search for PHP frameworks'));
        $handler1->run();

        $this->assertTrue($handler1->interrupted());
        $this->assertInstanceOf(ApprovalRequest::class, $handler1->getInterruptRequest());
        // Only the ChatNode inference ran; the tool never executed (no second inference).
        $this->assertSame(1, $provider->getCallCount());

        $request = $handler1->getInterruptRequest();
        $request->getAction('call_1')?->approve();

        // Resume: same workflowId → ChatNode:0 memoized, ToolNode:1 resumes and runs the tool.
        $agent2 = Agent::make(resumeToken: $workflowId);
        $agent2->setAiProvider($provider);
        $agent2->addTool($searchTool);
        $agent2->addMiddleware(ToolNode::class, new ToolApproval());
        $agent2->setExecutor($executor);

        $message = $agent2->chat(new UserMessage('Search for PHP frameworks'), $request)->getMessage();

        $this->assertSame('Here are the search results...', $message->getContent());
        $this->assertSame(2, $provider->getCallCount());
    }

    public function testChatNoToolsStepCleanupAfterCompletion(): void
    {
        $workflowId = 'agent_cleanup_test';
        $persistence = new InMemoryPersistence();
        $executor = new WorkflowExecutor(new LocalStepEngine($persistence));

        $provider = new FakeAIProvider(
            new AssistantMessage('Hello!'),
        );

        $agent = Agent::make(resumeToken: $workflowId);
        $agent->setAiProvider($provider);
        $agent->setExecutor($executor);

        $message = $agent->chat(new UserMessage('Hi'))->getMessage();

        $this->assertSame('Hello!', $message->getContent());
        $this->assertSame(1, $provider->getCallCount());

        // Steps should be cleaned up after successful completion
        $this->assertNull($persistence->load($workflowId, \NeuronAI\Agent\Nodes\ChatNode::class . '-0'));
    }

    public function testToolApprovalRejectsTool(): void
    {
        $workflowId = 'agent_rejection_test';
        $persistence = new InMemoryPersistence();
        $executor = new WorkflowExecutor(new LocalStepEngine($persistence));

        $searchTool = new SearchTool();

        $provider = new FakeAIProvider(
            new ToolCallMessage(null, [
                (clone $searchTool)->setCallId('call_1')->setInputs(['query' => 'PHP frameworks']),
            ]),
            new AssistantMessage('I see the search was rejected. Is there anything else I can help with?'),
        );

        $agent1 = Agent::make(resumeToken: $workflowId);
        $agent1->setAiProvider($provider);
        $agent1->addTool($searchTool);
        $agent1->addMiddleware(ToolNode::class, new ToolApproval());
        $agent1->setExecutor($executor);

        $handler1 = $agent1->chat(new UserMessage('Search for PHP frameworks'));
        $handler1->run();

        $this->assertTrue($handler1->interrupted());
        $request = $handler1->getInterruptRequest();
        $this->assertInstanceOf(ApprovalRequest::class, $request);
        $request->getAction('call_1')?->reject('Do not search the web.');

        // Resume with rejection: the tool is NOT executed; its rejection message
        // is fed back as the tool result and reaches the next inference.
        $agent2 = Agent::make(resumeToken: $workflowId);
        $agent2->setAiProvider($provider);
        $agent2->addTool($searchTool);
        $agent2->addMiddleware(ToolNode::class, new ToolApproval());
        $agent2->setExecutor($executor);

        $message = $agent2->chat(new UserMessage('Search for PHP frameworks'), $request)->getMessage();

        $this->assertSame(
            'I see the search was rejected. Is there anything else I can help with?',
            $message->getContent()
        );
    }

    public function testSuccessfulToolCallWithStepEngine(): void
    {
        $workflowId = 'agent_tool_success_test';
        $persistence = new InMemoryPersistence();
        $executor = new WorkflowExecutor(new LocalStepEngine($persistence));

        $searchTool = new SearchTool();

        $provider = new FakeAIProvider(
            new ToolCallMessage(null, [
                (clone $searchTool)->setCallId('call_1')->setInputs(['query' => 'PHP frameworks']),
            ]),
            new AssistantMessage('Based on the search results, here are the top PHP frameworks...'),
        );

        $agent = Agent::make(resumeToken: $workflowId);
        $agent->setAiProvider($provider);
        $agent->addTool($searchTool);
        $agent->setExecutor($executor);

        $message = $agent->chat(new UserMessage('Search for PHP frameworks'))->getMessage();

        $this->assertSame('Based on the search results, here are the top PHP frameworks...', $message->getContent());
        $this->assertSame(2, $provider->getCallCount());

        // Steps should be cleaned up after successful completion
        $this->assertNull($persistence->load($workflowId, ChatNode::class . '-0'));
    }

    public function testInterruptResumeWithFilePersistence(): void
    {
        $workflowId = 'agent_file_interrupt_test';
        $dir = sys_get_temp_dir() . '/neuron_test_' . $workflowId;

        $provider = new FakeAIProvider(
            new AssistantMessage('Hello!'),
        );

        $persistence = new FilePersistence($dir);
        $executor = new WorkflowExecutor(new LocalStepEngine($persistence));

        $agent = Agent::make(resumeToken: $workflowId);
        $agent->setAiProvider($provider);
        $agent->setExecutor($executor);

        $message = $agent->chat(new UserMessage('Hi'))->getMessage();
        $this->assertSame('Hello!', $message->getContent());

        // After successful completion, persistence file should be deleted
        $this->assertFileDoesNotExist($dir . '/' . $workflowId . '.workflow');

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
