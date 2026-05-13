<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools;

use NeuronAI\Agent\AgentState;
use NeuronAI\Agent\Events\AIInferenceEvent;
use NeuronAI\Agent\Events\ToolCallEvent;
use NeuronAI\Agent\Nodes\ToolNode;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Tools\HasInterrupt;
use NeuronAI\Tools\InterruptHandler;
use NeuronAI\Tools\Tool;
use NeuronAI\Workflow\Interrupt\ApprovalRequest;
use NeuronAI\Workflow\Interrupt\ToolsInterruptRequest;
use NeuronAI\Workflow\Interrupt\WorkflowInterrupt;
use PHPUnit\Framework\TestCase;

use function assert;
use function count;
use function implode;
use function serialize;
use function unserialize;

class InterruptHandlerTest extends TestCase
{
    public function test_tools_interrupt_request_add_and_get(): void
    {
        $request = new ToolsInterruptRequest('tools need attention');

        $this->assertFalse($request->hasRequests());
        $this->assertSame(0, $request->count());
        $this->assertNull($request->getRequest('tool_a'));

        $inner = new ApprovalRequest('tool a needs approval');
        $request->addRequest('tool_a', $inner);

        $this->assertTrue($request->hasRequests());
        $this->assertSame(1, $request->count());
        $this->assertSame($inner, $request->getRequest('tool_a'));
    }

    public function test_tools_interrupt_request_multiple_tools(): void
    {
        $request = new ToolsInterruptRequest('multiple tools need attention');
        $request->addRequest('tool_a', new ApprovalRequest('need approval for a'));
        $request->addRequest('tool_b', new ApprovalRequest('need approval for b'));

        $this->assertTrue($request->hasRequests());
        $this->assertSame(2, $request->count());
        $this->assertSame(
            'need approval for a',
            $request->getRequest('tool_a')?->getMessage()
        );
        $this->assertSame(
            'need approval for b',
            $request->getRequest('tool_b')?->getMessage()
        );
    }

    public function test_tool_with_has_interrupt_signals_interrupt(): void
    {
        $tool = $this->createInterruptibleTool('my_tool', true);

        $toolNode = new ToolNode(maxRuns: 10);
        $state = new AgentState();
        $toolCallMessage = new ToolCallMessage(null, [$tool]);
        $inferenceEvent = new AIInferenceEvent(instructions: 'Test', tools: [$tool]);
        $event = new ToolCallEvent($toolCallMessage, $inferenceEvent);
        $toolNode->setWorkflowContext($state, $event);

        $this->expectException(WorkflowInterrupt::class);
        $generator = $toolNode($event, $state);
        foreach ($generator as $_) {
            $_ = null;
        }
    }

    public function test_tool_without_interrupt_passes_through(): void
    {
        $tool = $this->createInterruptibleTool('my_tool', false);

        $toolNode = new ToolNode(maxRuns: 10);
        $state = new AgentState();
        $toolCallMessage = new ToolCallMessage(null, [$tool]);
        $inferenceEvent = new AIInferenceEvent(instructions: 'Test', tools: [$tool]);
        $event = new ToolCallEvent($toolCallMessage, $inferenceEvent);
        $toolNode->setWorkflowContext($state, $event);
        $generator = $toolNode($event, $state);
        foreach ($generator as $_) {
            $_ = null;
        }

        $this->assertSame('executed', $tool->getResult());
    }

    public function test_interrupt_carries_correct_message_via_tools_request(): void
    {
        $tool = $this->createInterruptibleTool('my_tool', true, 'custom message');

        $toolNode = new ToolNode(maxRuns: 10);
        $state = new AgentState();
        $toolCallMessage = new ToolCallMessage(null, [$tool]);
        $inferenceEvent = new AIInferenceEvent(instructions: 'Test', tools: [$tool]);
        $event = new ToolCallEvent($toolCallMessage, $inferenceEvent);
        $toolNode->setWorkflowContext($state, $event);

        try {
            $generator = $toolNode($event, $state);
            foreach ($generator as $_) {
                $_ = null;
            }
            $this->fail('Expected WorkflowInterrupt');
        } catch (WorkflowInterrupt $interrupt) {
            $request = $interrupt->getRequest();
            $this->assertInstanceOf(ToolsInterruptRequest::class, $request);

            $subRequest = $request->getRequest('my_tool');
            $this->assertNotNull($subRequest);
            $this->assertSame('custom message', $subRequest->getMessage());
        }
    }

    public function test_resume_request_injected_into_tool(): void
    {
        $tool = $this->createInterruptibleTool('my_tool', false);

        $resumeRequest = new ToolsInterruptRequest('resume response');
        $resumeRequest->addRequest('my_tool', new ApprovalRequest('user approved'));

        $toolNode = new ToolNode(maxRuns: 10);
        $state = new AgentState();
        $toolCallMessage = new ToolCallMessage(null, [$tool]);
        $inferenceEvent = new AIInferenceEvent(instructions: 'Test', tools: [$tool]);
        $event = new ToolCallEvent($toolCallMessage, $inferenceEvent);
        $toolNode->setWorkflowContext($state, $event, $resumeRequest);
        $generator = $toolNode($event, $state);
        foreach ($generator as $_) {
            $_ = null;
        }

        $receivedRequest = $tool->getResumeRequest();
        $this->assertNotNull($receivedRequest);
        $this->assertSame('user approved', $receivedRequest->getMessage());
    }

    public function test_tool_without_has_interrupt_ignored(): void
    {
        $tool = Tool::make('regular_tool', 'A regular tool')
            ->setCallable(fn (): string => 'done');
        $tool->setCallId('call_1');
        $tool->setInputs([]);

        $toolNode = new ToolNode(maxRuns: 10);
        $state = new AgentState();
        $toolCallMessage = new ToolCallMessage(null, [$tool]);
        $inferenceEvent = new AIInferenceEvent(instructions: 'Test', tools: [$tool]);
        $event = new ToolCallEvent($toolCallMessage, $inferenceEvent);
        $toolNode->setWorkflowContext($state, $event);
        $generator = $toolNode($event, $state);
        foreach ($generator as $_) {
            $_ = null;
        }

        $this->assertSame('done', $tool->getResult());
    }

    public function test_multi_step_state_tracking(): void
    {
        $tool = new class ('multi_step', ['confirm step 1', 'confirm step 2']) extends Tool implements HasInterrupt {
            use InterruptHandler;

            private int $currentStep = 0;

            public function __construct(
                string $name,
                private readonly array $steps,
            ) {
                parent::__construct(name: $name);
            }

            public function __invoke(mixed ...$params): string
            {
                $resume = $this->getResumeRequest();

                if ($resume instanceof \NeuronAI\Workflow\Interrupt\InterruptRequest) {
                    $this->currentStep++;
                }

                if ($this->currentStep >= count($this->steps)) {
                    return 'All steps completed: '.implode(', ', $this->steps);
                }

                $this->setInterruptRequest(
                    new ApprovalRequest($this->steps[$this->currentStep])
                );

                return '';
            }
        };

        // Step 1: first call signals interrupt
        $result = $tool->__invoke();
        $this->assertSame('', $result);
        $this->assertSame('confirm step 1', $tool->getInterruptRequest()?->getMessage());

        // Resume: step 1 approved, tool moves to step 2
        $tool->setInterruptRequest(null);
        $tool->setResumeRequest(new ApprovalRequest('step 1 approved'));
        $result = $tool->__invoke();
        $this->assertSame('', $result);
        $this->assertSame('confirm step 2', $tool->getInterruptRequest()?->getMessage());

        // Resume: step 2 approved, tool completes
        $tool->setInterruptRequest(null);
        $tool->setResumeRequest(new ApprovalRequest('step 2 approved'));
        $result = $tool->__invoke();
        $this->assertSame('All steps completed: confirm step 1, confirm step 2', $result);
        $this->assertNull($tool->getInterruptRequest());
    }

    public function test_multi_step_through_tool_node(): void
    {
        $tool = new class ('multi_step', ['first approval', 'second approval']) extends Tool implements HasInterrupt {
            use InterruptHandler;

            private int $currentStep = 0;

            public function __construct(
                string $name,
                private readonly array $steps,
            ) {
                parent::__construct(name: $name);
            }

            public function __invoke(mixed ...$params): string
            {
                $resume = $this->getResumeRequest();

                if ($resume instanceof \NeuronAI\Workflow\Interrupt\InterruptRequest) {
                    $this->currentStep++;
                }

                if ($this->currentStep >= count($this->steps)) {
                    $this->setResult('All steps completed: '.implode(', ', $this->steps));

                    return $this->getResult();
                }

                $this->setInterruptRequest(
                    new ApprovalRequest($this->steps[$this->currentStep])
                );

                return '';
            }
        };
        $tool->setInputs([]);

        $toolNode = new ToolNode(maxRuns: 10);
        $state = new AgentState();

        // First call: step 1 interrupt
        $toolCallMessage = new ToolCallMessage(null, [$tool]);
        $inferenceEvent = new AIInferenceEvent(instructions: 'Test', tools: [$tool]);
        $event = new ToolCallEvent($toolCallMessage, $inferenceEvent);
        $toolNode->setWorkflowContext($state, $event);

        try {
            $generator = $toolNode($event, $state);
            foreach ($generator as $_) {
                $_ = null;
            }
            $this->fail('Expected WorkflowInterrupt');
        } catch (WorkflowInterrupt $interrupt) {
            $request = $interrupt->getRequest();
            $this->assertInstanceOf(ToolsInterruptRequest::class, $request);
            $this->assertSame('first approval', $request->getRequest('multi_step')?->getMessage());
        }

        // Resume: step 1 approved, tool moves to step 2 (which also interrupts)
        $resume = new ToolsInterruptRequest('resume');
        $resume->addRequest('multi_step', new ApprovalRequest('step 1 approved'));
        $toolCallMessage = new ToolCallMessage(null, [$tool]);
        $inferenceEvent = new AIInferenceEvent(instructions: 'Test', tools: [$tool]);
        $event = new ToolCallEvent($toolCallMessage, $inferenceEvent);
        $toolNode->setWorkflowContext($state, $event, $resume);

        try {
            $generator = $toolNode($event, $state);
            foreach ($generator as $_) {
                $_ = null;
            }
            $this->fail('Expected WorkflowInterrupt');
        } catch (WorkflowInterrupt $interrupt) {
            $request = $interrupt->getRequest();
            $this->assertInstanceOf(ToolsInterruptRequest::class, $request);
            $this->assertSame('second approval', $request->getRequest('multi_step')?->getMessage());
        }

        // Resume again: step 2 approved, tool completes
        $resume = new ToolsInterruptRequest('resume');
        $resume->addRequest('multi_step', new ApprovalRequest('step 2 approved'));
        $toolCallMessage = new ToolCallMessage(null, [$tool]);
        $inferenceEvent = new AIInferenceEvent(instructions: 'Test', tools: [$tool]);
        $event = new ToolCallEvent($toolCallMessage, $inferenceEvent);
        $toolNode->setWorkflowContext($state, $event, $resume);

        $generator = $toolNode($event, $state);
        foreach ($generator as $_) {
            $_ = null;
        }

        $this->assertSame('All steps completed: first approval, second approval', $tool->getResult());
    }

    public function test_multi_step_state_survives_serialization(): void
    {
        $tool = new MultiStepTestTool(['step A', 'step B', 'step C']);

        // Step 1: signal interrupt
        $tool->__invoke();
        $this->assertSame(0, $tool->getCurrentStep());
        $this->assertNotNull($tool->getInterruptRequest());

        // Simulate serialization across interrupt persist + resume
        $serialized = serialize($tool);
        $restored = unserialize($serialized);
        assert($restored instanceof MultiStepTestTool);

        // State survived
        $this->assertSame(0, $restored->getCurrentStep());
        $this->assertNotNull($restored->getInterruptRequest());
        $this->assertSame('step A', $restored->getInterruptRequest()->getMessage());

        // Simulate ToolNode clearing old interrupt and injecting resume
        $restored->setInterruptRequest(null);
        $restored->setResumeRequest(new ApprovalRequest('step A approved'));

        // Step 2: advance to next step
        $result = $restored->__invoke();
        $this->assertSame('', $result);
        $this->assertSame(1, $restored->getCurrentStep());
        $this->assertSame('step B', $restored->getInterruptRequest()?->getMessage());

        // Serialize again (interrupt at step 2)
        $serialized2 = serialize($restored);
        $restored2 = unserialize($serialized2);
        assert($restored2 instanceof MultiStepTestTool);

        $this->assertSame(1, $restored2->getCurrentStep());
        $this->assertSame('step B', $restored2->getInterruptRequest()?->getMessage());

        // Resume again, clear old interrupt, set resume
        $restored2->setInterruptRequest(null);
        $restored2->setResumeRequest(new ApprovalRequest('step B approved'));

        $result = $restored2->__invoke();
        $this->assertSame(2, $restored2->getCurrentStep());

        // Resume (no more interrupts)
        $restored2->setInterruptRequest(null);
        $restored2->setResumeRequest(new ApprovalRequest('step C approved'));

        $result = $restored2->__invoke();
        $this->assertSame('All steps completed: step A, step B, step C', $result);
        $this->assertNull($restored2->getInterruptRequest());
    }

    private function createInterruptibleTool(
        string $name,
        bool $shouldInterrupt,
        string $interruptMessage = 'interrupt requested'
    ): HasInterrupt {
        return new class ($name, $shouldInterrupt, $interruptMessage) extends Tool implements HasInterrupt {
            use InterruptHandler;

            public function __construct(
                string $name,
                private readonly bool $shouldInterrupt,
                private readonly string $interruptMessage
            ) {
                parent::__construct(name: $name);
            }

            public function __invoke(mixed ...$params): string
            {
                if ($this->shouldInterrupt) {
                    $this->setInterruptRequest(
                        new ApprovalRequest($this->interruptMessage)
                    );

                    return '';
                }

                return 'executed';
            }
        };
    }

}
