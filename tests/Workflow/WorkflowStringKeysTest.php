<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow;

use NeuronAI\Workflow\Edge;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\Workflow;
use NeuronAI\Workflow\WorkflowState;
use PHPUnit\Framework\TestCase;

// Calculator nodes that can be reused with different values
class AddNode extends Node
{
    public function __construct(private int $value)
    {
    }

    public function run(WorkflowState $state): WorkflowState
    {
        $current = $state->get('value', 0);
        $state->set('value', $current + $this->value);
        $history = $state->get('history', []);
        $history[] = "Added {$this->value}";
        $state->set('history', $history);
        return $state;
    }
}

class MultiplyNode extends Node
{
    public function __construct(private int $value)
    {
    }

    public function run(WorkflowState $state): WorkflowState
    {
        $current = $state->get('value', 0);
        $state->set('value', $current * $this->value);
        $history = $state->get('history', []);
        $history[] = "Multiplied by {$this->value}";
        $state->set('history', $history);
        return $state;
    }
}

class SubtractNode extends Node
{
    public function __construct(private int $value)
    {
    }

    public function run(WorkflowState $state): WorkflowState
    {
        $current = $state->get('value', 0);
        $state->set('value', $current - $this->value);
        $history = $state->get('history', []);
        $history[] = "Subtracted {$this->value}";
        $state->set('history', $history);
        return $state;
    }
}

class FinishEvenNode extends Node
{
    public function run(WorkflowState $state): WorkflowState
    {
        $state->set('result_type', 'even');
        return $state;
    }
}

class FinishOddNode extends Node
{
    public function run(WorkflowState $state): WorkflowState
    {
        $state->set('result_type', 'odd');
        return $state;
    }
}

// Test workflow that uses string keys
class CalculatorWorkflow extends Workflow
{
    public function nodes(): array
    {
        return [
            'add1' => new AddNode(1),
            'multiply3_first' => new MultiplyNode(3),
            'multiply3_second' => new MultiplyNode(3),
            'sub1' => new SubtractNode(1),
            'finish_even' => new FinishEvenNode(),
            'finish_odd' => new FinishOddNode()
        ];
    }

    public function edges(): array
    {
        return [
            // ((startingValue + 1) * 3) * 3) - 1
            new Edge('add1', 'multiply3_first'),
            new Edge('multiply3_first', 'multiply3_second'),
            new Edge('multiply3_second', 'sub1'),

            // Branch based on even/odd
            new Edge('sub1', 'finish_even', fn ($state) => $state->get('value') % 2 === 0),
            new Edge('sub1', 'finish_odd', fn ($state) => $state->get('value') % 2 !== 0)
        ];
    }

    protected function start(): string
    {
        return 'add1';
    }

    protected function end(): array
    {
        return ['finish_even', 'finish_odd'];
    }
}

class WorkflowStringKeysTest extends TestCase
{
    public function test_workflow_with_string_keys(): void
    {
        $workflow = new CalculatorWorkflow();

        // Test with initial value 2: ((2 + 1) * 3) * 3) - 1 = 26 (even)
        $initialState = new WorkflowState(['value' => 2]);
        $result = $workflow->run($initialState);

        $this->assertEquals(26, $result->get('value'));
        $this->assertEquals('even', $result->get('result_type'));
        $this->assertContains('Added 1', $result->get('history'));
        $this->assertContains('Multiplied by 3', $result->get('history'));
        $this->assertContains('Subtracted 1', $result->get('history'));
    }

    public function test_workflow_with_string_keys_odd_result(): void
    {
        $workflow = new CalculatorWorkflow();

        // Test with initial value 1: ((1 + 1) * 3) * 3) - 1 = 17 (odd)
        $initialState = new WorkflowState(['value' => 1]);
        $result = $workflow->run($initialState);

        $this->assertEquals(17, $result->get('value'));
        $this->assertEquals('odd', $result->get('result_type'));
    }

    public function test_programmatic_workflow_with_string_keys(): void
    {
        $workflow = new Workflow();
        $workflow->addNodes([
            'add1' => new AddNode(1),
            'multiply2' => new MultiplyNode(2),
            'finish_even' => new FinishEvenNode(),
            'finish_odd' => new FinishOddNode()
        ])
        ->addEdges([
            new Edge('add1', 'multiply2'),
            new Edge('multiply2', 'finish_even', fn ($state) => $state->get('value') % 2 === 0),
            new Edge('multiply2', 'finish_odd', fn ($state) => $state->get('value') % 2 !== 0)
        ])
        ->setStart('add1')
        ->setEnd('finish_even')
        ->setEnd('finish_odd');

        // Test with initial value 3: (3 + 1) * 2 = 8 (even)
        $initialState = new WorkflowState(['value' => 3]);
        $result = $workflow->run($initialState);

        $this->assertEquals(8, $result->get('value'));
        $this->assertEquals('even', $result->get('result_type'));
    }

    public function test_mermaid_export_with_string_keys(): void
    {
        $workflow = new Workflow();
        $workflow->addNodes([
            'start' => new AddNode(1),
            'middle' => new MultiplyNode(2),
            'finish' => new FinishEvenNode()
        ])
        ->addEdges([
            new Edge('start', 'middle'),
            new Edge('middle', 'finish')
        ])
        ->setStart('start')
        ->setEnd('finish');

        $export = $workflow->export();

        $this->assertStringContainsString('start --> middle', $export);
        $this->assertStringContainsString('middle --> finish', $export);
    }

    public function test_backward_compatibility_with_class_names(): void
    {
        // This test ensures the old behavior still works
        $workflow = new Workflow();
        $workflow->addNode(new StartNode())
            ->addNode(new FinishNode())
            ->addEdge(new Edge(StartNode::class, FinishNode::class))
            ->setStart(StartNode::class)
            ->setEnd(FinishNode::class);

        $result = $workflow->run();

        $this->assertEquals('end', $result->get('step'));
    }

    public function test_mixed_mode_nodes_and_edges(): void
    {
        // Test mixing both approaches - indexed array with class name edges
        $workflow = new Workflow();
        $workflow->addNodes([
            new StartNode(),
            new MiddleNode(),
            new FinishNode()
        ])
        ->addEdges([
            new Edge(StartNode::class, MiddleNode::class),
            new Edge(MiddleNode::class, FinishNode::class)
        ])
        ->setStart(StartNode::class)
        ->setEnd(FinishNode::class);

        $result = $workflow->run();

        $this->assertEquals('end', $result->get('step'));
        $this->assertEquals(1, $result->get('counter'));
    }
}
