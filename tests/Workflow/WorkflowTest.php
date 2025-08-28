<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow;

use NeuronAI\Exceptions\WorkflowException;
use NeuronAI\Tests\Workflow\Stubs\SecondEvent;
use NeuronAI\Tests\Workflow\Stubs\ThirdEvent;
use NeuronAI\Workflow\Persistence\InMemoryPersistence;
use NeuronAI\Workflow\StartEvent;
use NeuronAI\Workflow\StopEvent;
use NeuronAI\Workflow\Workflow;
use NeuronAI\Workflow\WorkflowInterrupt;
use NeuronAI\Workflow\WorkflowState;
use PHPUnit\Framework\TestCase;
use NeuronAI\Tests\Workflow\Stubs\ConditionalNode;
use NeuronAI\Tests\Workflow\Stubs\FirstEvent;
use NeuronAI\Tests\Workflow\Stubs\InterruptableNode;
use NeuronAI\Tests\Workflow\Stubs\NodeForSecond;
use NeuronAI\Tests\Workflow\Stubs\NodeForThird;
use NeuronAI\Tests\Workflow\Stubs\NodeOne;
use NeuronAI\Tests\Workflow\Stubs\NodeThree;
use NeuronAI\Tests\Workflow\Stubs\NodeTwo;

class WorkflowTest extends TestCase
{
    public function testBasicLinearWorkflowExecution(): void
    {
        $workflow = Workflow::make()
            ->addNodes([
                StartEvent::class => new NodeOne(),
                FirstEvent::class => new NodeTwo(),
                SecondEvent::class => new NodeThree(),
            ]);

        $finalState = $workflow->run();

        $this->assertTrue($finalState->get('node_one_executed'));
        $this->assertTrue($finalState->get('node_two_executed'));
        $this->assertTrue($finalState->get('node_three_executed'));
        $this->assertEquals('First complete', $finalState->get('first_message'));
        $this->assertEquals('Second complete', $finalState->get('second_message'));
    }

    public function testWorkflowWithInitialState(): void
    {
        $workflow = Workflow::make()
            ->addNodes([
                StartEvent::class => new NodeOne(),
                FirstEvent::class => new NodeTwo(),
                SecondEvent::class => new NodeThree(),
            ]);

        $initialState = new WorkflowState(['initial_data' => 'test']);
        $finalState = $workflow->run($initialState);

        $this->assertEquals('test', $finalState->get('initial_data'));
        $this->assertTrue($finalState->get('node_one_executed'));
    }

    public function testNodeClassStringInstantiation(): void
    {
        $workflow = Workflow::make()
            ->addNodes([
                StartEvent::class => new NodeOne(),
                FirstEvent::class => new NodeTwo(),
                SecondEvent::class => new NodeThree(),
            ]);

        $finalState = $workflow->run();

        $this->assertTrue($finalState->get('node_one_executed'));
        $this->assertTrue($finalState->get('node_two_executed'));
        $this->assertTrue($finalState->get('node_three_executed'));
    }

    public function testEventNodeMapBuilding(): void
    {
        $workflow = Workflow::make()
            ->addNodes([
                StartEvent::class => new NodeOne(),
                FirstEvent::class => new NodeTwo(),
                SecondEvent::class => new NodeThree(),
            ]);

        $eventNodeMap = $workflow->getEventNodeMap();

        $this->assertArrayHasKey(StartEvent::class, $eventNodeMap);
        $this->assertArrayHasKey(FirstEvent::class, $eventNodeMap);
    }

    public function testConditionalNodeWithUnionReturnType(): void
    {
        $workflow = Workflow::make()
            ->addNodes([
                StartEvent::class => new NodeOne(),
                FirstEvent::class => new ConditionalNode(),
                SecondEvent::class => new NodeForSecond(),
                ThirdEvent::class => new NodeForThird(),
            ]);

        // Test second path
        $state = new WorkflowState(['condition' => 'second']);
        $finalState = $workflow->run($state);

        $this->assertTrue($finalState->get('conditional_node_executed'));
        $this->assertTrue($finalState->get('second_path_executed'));
        $this->assertFalse($finalState->has('third_path_executed'));
        $this->assertEquals('Conditional chose second', $finalState->get('final_second_message'));

        // Test third path
        $state = new WorkflowState(['condition' => 'third']);
        $finalState = $workflow->run($state);

        $this->assertTrue($finalState->get('conditional_node_executed'));
        $this->assertTrue($finalState->get('third_path_executed'));
        $this->assertFalse($finalState->has('second_path_executed'));
        $this->assertEquals('Conditional chose third', $finalState->get('final_third_message'));
    }

    public function testWorkflowValidationFailsWithNoStartNode(): void
    {
        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage('No nodes found that accept StartEvent');

        $workflow = Workflow::make()
            ->addNodes([
                FirstEvent::class => new NodeTwo(),
                SecondEvent::class => new NodeThree(),
            ]);

        $workflow->run();
    }

    public function testWorkflowFailsWhenNoNodeAcceptsEvent(): void
    {
        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage('No node found that accepts event');

        $workflow = Workflow::make()
            ->addNodes([
                StartEvent::class => new NodeOne(),
                // Missing NodeTwo that accepts FirstEvent
                SecondEvent::class => new NodeThree(),
            ]);

        $workflow->run();
    }

    public function testWorkflowInterrupt(): void
    {
        $this->expectException(WorkflowInterrupt::class);

        $workflow = Workflow::make(new InMemoryPersistence(), 'test-workflow')
            ->addNodes([
                StartEvent::class => new NodeOne(),
                FirstEvent::class => new InterruptableNode(),
                SecondEvent::class => new NodeThree(),
            ]);

        $workflow->run();
    }

    public function testWorkflowResume(): void
    {
        $persistence = new InMemoryPersistence();
        $workflow = Workflow::make($persistence, 'test-workflow')
            ->addNodes([
                StartEvent::class => new NodeOne(),
                FirstEvent::class => new InterruptableNode(),
                SecondEvent::class => new NodeThree(),
            ]);

        try {
            $workflow->run();
            $this->fail('Expected WorkflowInterrupt exception');
        } catch (WorkflowInterrupt $interrupt) {
            $this->assertEquals(['message' => 'Need human input'], $interrupt->getData());
            $this->assertInstanceOf(InterruptableNode::class, $interrupt->getCurrentNode());
        }

        // Resume with human feedback
        $finalState = $workflow->resume('human input received');

        $this->assertTrue($finalState->get('interruptable_node_executed'));
        $this->assertEquals('human input received', $finalState->get('received_feedback'));
    }

    public function testWorkflowExport(): void
    {
        $workflow = Workflow::make()
            ->addNodes([
                StartEvent::class => new NodeOne(),
                FirstEvent::class => new NodeTwo(),
                SecondEvent::class => new NodeThree(),
            ]);

        $mermaidOutput = $workflow->export();

        $this->assertStringContainsString('StartEvent --> NodeOne', $mermaidOutput);
        $this->assertStringContainsString('NodeOne --> FirstEvent', $mermaidOutput);
        $this->assertStringContainsString('FirstEvent --> NodeTwo', $mermaidOutput);
        $this->assertStringContainsString('NodeTwo --> SecondEvent', $mermaidOutput);
        $this->assertStringContainsString('SecondEvent --> NodeThree', $mermaidOutput);
        $this->assertStringContainsString('NodeThree --> StopEvent', $mermaidOutput);
    }

    public function testWorkflowWithObservableEvents(): void
    {
        $events = [];

        $workflow = Workflow::make()
            ->addNodes([
                StartEvent::class => new NodeOne(),
                FirstEvent::class => new NodeTwo(),
                SecondEvent::class => new NodeThree(),
            ]);

        $workflow->attach(new class($events) implements \SplObserver {
            public function __construct(private array &$events) {}

            public function update(\SplSubject $subject, string $event = null, mixed $data = null): void
            {
                $this->events[] = $event;
            }
        });

        $workflow->run();

        $this->assertContains('workflow-start', $events);
        $this->assertContains('workflow-node-start', $events);
        $this->assertContains('workflow-node-end', $events);
        $this->assertContains('workflow-end', $events);
    }
}
