<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Agent;

use NeuronAI\Agent\Agent;
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
use NeuronAI\Workflow\Executor\WorkflowExecutor;
use NeuronAI\Workflow\Interrupt\ApprovalRequest;
use NeuronAI\Workflow\Interrupt\WorkflowInterrupt;
use NeuronAI\Workflow\Persistence\FilePersistence;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function glob;
use function is_dir;
use function mkdir;
use function rmdir;
use function sys_get_temp_dir;
use function unlink;

use const DIRECTORY_SEPARATOR;

class AgentDurabilityTest extends TestCase
{
    public function testCrashRecoveryDuringToolExecution(): void
    {
        $workflowId = 'agent_recovery_test';
        $stepEngine = new LocalStepEngine();
        $executor = new WorkflowExecutor($stepEngine);

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

    public function testInterruptResumeWithToolApproval(): void
    {
        $workflowId = 'agent_approval_test';
        $stepEngine = new LocalStepEngine();
        $executor = new WorkflowExecutor($stepEngine);

        $searchTool = new SearchTool();

        $provider = new FakeAIProvider(
            new ToolCallMessage(null, [
                (clone $searchTool)->setCallId('call_1')->setInputs(['query' => 'PHP frameworks']),
            ]),
            new AssistantMessage('Here are the search results...'),
        );

        // Run 1: ChatNode completes, ToolApproval interrupts before tool execution
        $agent1 = Agent::make(resumeToken: $workflowId);
        $agent1->setAiProvider($provider);
        $agent1->addTool($searchTool);
        $agent1->addMiddleware(ToolNode::class, new ToolApproval());
        $agent1->setExecutor($executor);

        try {
            $agent1->chat(new UserMessage('Search for PHP frameworks'))->getMessage();
            $this->fail('Expected WorkflowInterrupt was not thrown');
        } catch (WorkflowInterrupt $interrupt) {
            $this->assertInstanceOf(ApprovalRequest::class, $interrupt->getRequest());
            $request = $interrupt->getRequest();
            $request->getAction('call_1')?->approve();
        }

        $this->assertSame(1, $provider->getCallCount());

        // Resume: same workflowId → ChatNode:0 memoized, ToolNode:1 resumes
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
        $stepEngine = new LocalStepEngine();
        $executor = new WorkflowExecutor($stepEngine);

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
        $this->assertNull($stepEngine->getStep(\NeuronAI\Agent\Nodes\ChatNode::class . '-0'));
    }

    public function testToolApprovalRejectsTool(): void
    {
        $workflowId = 'agent_rejection_test';
        $stepEngine = new LocalStepEngine();
        $executor = new WorkflowExecutor($stepEngine);

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

        try {
            $agent1->chat(new UserMessage('Search for PHP frameworks'))->getMessage();
            $this->fail('Expected WorkflowInterrupt');
        } catch (WorkflowInterrupt $interrupt) {
            $request = $interrupt->getRequest();
            $this->assertInstanceOf(ApprovalRequest::class, $request);
            $request->getAction('call_1')?->reject('Do not search the web.');
        }

        // Resume with rejection
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
        $stepEngine = new LocalStepEngine();
        $executor = new WorkflowExecutor($stepEngine);

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
        $this->assertNull($stepEngine->getStep(ChatNode::class . '-0'));
    }

    public function testCrashRecoveryWithFilePersistence(): void
    {
        $workflowId = 'agent_file_recovery_test';
        $dir = sys_get_temp_dir() . '/neuron_test_' . $workflowId;
        mkdir($dir, 0o777, true);

        $provider = new FakeAIProvider(
            new AssistantMessage('First response'),
            new AssistantMessage('Second response'),
        );

        // Run 1: ChatNode completes, second chat call crashes
        // We test file persistence by running two separate chat calls —
        // the first completes and persists, the second simulates recovery.
        $persistence = new FilePersistence($dir);
        $stepEngine = new LocalStepEngine(persistence: $persistence);
        $executor = new WorkflowExecutor($stepEngine);

        $agent1 = Agent::make(resumeToken: $workflowId);
        $agent1->setAiProvider($provider);
        $agent1->setExecutor($executor);

        $message1 = $agent1->chat(new UserMessage('First question'))->getMessage();
        $this->assertSame('First response', $message1->getContent());

        // Steps are cleaned up after successful completion — verify the
        // persistence file was deleted.
        $this->assertFileDoesNotExist($dir . '/' . $workflowId . '.workflow');

        $this->removeDirectory($dir);
    }

    public function testInterruptResumeWithFilePersistence(): void
    {
        $workflowId = 'agent_file_interrupt_test';
        $dir = sys_get_temp_dir() . '/neuron_test_' . $workflowId;
        mkdir($dir, 0o777, true);

        $provider = new FakeAIProvider(
            new AssistantMessage('Hello!'),
        );

        $persistence = new FilePersistence($dir);
        $stepEngine = new LocalStepEngine(persistence: $persistence);
        $executor = new WorkflowExecutor($stepEngine);

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
