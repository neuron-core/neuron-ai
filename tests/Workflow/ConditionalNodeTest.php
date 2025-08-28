<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow;

use NeuronAI\Workflow\Workflow;
use NeuronAI\Workflow\WorkflowState;
use PHPUnit\Framework\TestCase;
use NeuronAI\Tests\Workflow\Stubs\ConditionalNode;
use NeuronAI\Tests\Workflow\Stubs\FirstEvent;
use NeuronAI\Tests\Workflow\Stubs\NodeForSecond;
use NeuronAI\Tests\Workflow\Stubs\NodeForThird;
use NeuronAI\Tests\Workflow\Stubs\NodeOne;
use NeuronAI\Tests\Workflow\Stubs\SecondEvent;
use NeuronAI\Tests\Workflow\Stubs\ThirdEvent;

class ConditionalNodeTest extends TestCase
{
    public function testConditionalNodeUnionReturnType(): void
    {
        $node = new ConditionalNode();
        $state = new WorkflowState(['condition' => 'second']);
        
        $result = $node->run(new FirstEvent(), $state);
        
        $this->assertInstanceOf(SecondEvent::class, $result);
        $this->assertEquals('Conditional chose second', $result->message);
        $this->assertTrue($state->get('conditional_node_executed'));
    }

    public function testConditionalNodeAlternativePath(): void
    {
        $node = new ConditionalNode();
        $state = new WorkflowState(['condition' => 'third']);
        
        $result = $node->run(new FirstEvent(), $state);
        
        $this->assertInstanceOf(ThirdEvent::class, $result);
        $this->assertEquals('Conditional chose third', $result->message);
        $this->assertTrue($state->get('conditional_node_executed'));
    }

    public function testConditionalNodeDefaultBehavior(): void
    {
        $node = new ConditionalNode();
        $state = new WorkflowState(); // No condition set
        
        $result = $node->run(new FirstEvent(), $state);
        
        // Should default to SecondEvent
        $this->assertInstanceOf(SecondEvent::class, $result);
        $this->assertEquals('Conditional chose second', $result->message);
    }

    public function testConditionalWorkflowSecondPath(): void
    {
        $workflow = Workflow::make()
            ->addNodes([
                new NodeOne(),
                new ConditionalNode(),
                new NodeForSecond(),
                new NodeForThird(),
            ]);

        $initialState = new WorkflowState(['condition' => 'second']);
        $finalState = $workflow->run($initialState);

        // Verify execution path
        $this->assertTrue($finalState->get('node_one_executed'));
        $this->assertTrue($finalState->get('conditional_node_executed'));
        $this->assertTrue($finalState->get('second_path_executed'));
        $this->assertFalse($finalState->has('third_path_executed'));
        
        // Verify data flow
        $this->assertEquals('Conditional chose second', $finalState->get('final_second_message'));
    }

    public function testConditionalWorkflowThirdPath(): void
    {
        $workflow = Workflow::make()
            ->addNodes([
                new NodeOne(),
                new ConditionalNode(),
                new NodeForSecond(),
                new NodeForThird(),
            ]);

        $initialState = new WorkflowState(['condition' => 'third']);
        $finalState = $workflow->run($initialState);

        // Verify execution path
        $this->assertTrue($finalState->get('node_one_executed'));
        $this->assertTrue($finalState->get('conditional_node_executed'));
        $this->assertTrue($finalState->get('third_path_executed'));
        $this->assertFalse($finalState->has('second_path_executed'));
        
        // Verify data flow
        $this->assertEquals('Conditional chose third', $finalState->get('final_third_message'));
    }

    public function testConditionalNodeEventTypeMatching(): void
    {
        // Create workflow and build event node map
        $workflow = Workflow::make()
            ->addNodes([
                new NodeOne(),
                new ConditionalNode(),
                new NodeForSecond(),
                new NodeForThird(),
            ]);

        $eventNodeMap = $workflow->getEventNodeMap();

        // Verify that both SecondEvent and ThirdEvent are handled
        $this->assertArrayHasKey(SecondEvent::class, $eventNodeMap);
        $this->assertArrayHasKey(ThirdEvent::class, $eventNodeMap);
        
        // Verify correct node assignments
        $this->assertArrayHasKey(NodeForSecond::class, $eventNodeMap[SecondEvent::class]);
        $this->assertArrayHasKey(NodeForThird::class, $eventNodeMap[ThirdEvent::class]);
    }

    public function testMultipleConditionalExecutions(): void
    {
        $workflow = Workflow::make()
            ->addNodes([
                new NodeOne(),
                new ConditionalNode(),
                new NodeForSecond(),
                new NodeForThird(),
            ]);

        // Test multiple executions with different conditions
        $conditions = ['second', 'third', 'second', 'third'];
        $results = [];

        foreach ($conditions as $condition) {
            $initialState = new WorkflowState(['condition' => $condition]);
            $finalState = $workflow->run($initialState);
            $results[] = [
                'condition' => $condition,
                'second_executed' => $finalState->has('second_path_executed'),
                'third_executed' => $finalState->has('third_path_executed'),
            ];
        }

        // Verify each execution followed correct path
        $this->assertTrue($results[0]['second_executed'] && !$results[0]['third_executed']);
        $this->assertTrue(!$results[1]['second_executed'] && $results[1]['third_executed']);
        $this->assertTrue($results[2]['second_executed'] && !$results[2]['third_executed']);
        $this->assertTrue(!$results[3]['second_executed'] && $results[3]['third_executed']);
    }

    public function testConditionalNodeStateIsolation(): void
    {
        $workflow = Workflow::make()
            ->addNodes([
                new NodeOne(),
                new ConditionalNode(),
                new NodeForSecond(),
                new NodeForThird(),
            ]);

        // First execution - second path
        $state1 = new WorkflowState(['condition' => 'second', 'execution' => 1]);
        $finalState1 = $workflow->run($state1);

        // Second execution - third path
        $state2 = new WorkflowState(['condition' => 'third', 'execution' => 2]);
        $finalState2 = $workflow->run($state2);

        // Verify states are isolated
        $this->assertEquals(1, $finalState1->get('execution'));
        $this->assertEquals(2, $finalState2->get('execution'));
        
        $this->assertTrue($finalState1->get('second_path_executed'));
        $this->assertFalse($finalState1->has('third_path_executed'));
        
        $this->assertTrue($finalState2->get('third_path_executed'));
        $this->assertFalse($finalState2->has('second_path_executed'));
    }
}