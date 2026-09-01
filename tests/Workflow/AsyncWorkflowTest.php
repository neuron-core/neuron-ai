<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow;

use Amp\Future;
use NeuronAI\Tests\Support\ExecutorTestHelpers;
use NeuronAI\Tests\Workflow\Stub\AsyncDelayNode;
use NeuronAI\Tests\Workflow\Stub\FirstNode;
use NeuronAI\Tests\Workflow\Stub\SecondNode;
use NeuronAI\Workflow\Workflow;
use NeuronAI\Workflow\WorkflowState;
use PHPUnit\Framework\TestCase;
use function Amp\async;
use function microtime;

class AsyncWorkflowTest extends TestCase
{
    use ExecutorTestHelpers;

    public function test_basic_async_execution(): void
    {
        $workflow = Workflow::make()
            ->addNodes([
                new FirstNode(),
                new SecondNode(),
            ]);


        $result = async(fn (): \NeuronAI\Workflow\WorkflowState => $this->execute($workflow))->await();

        $this->assertInstanceOf(WorkflowState::class, $result);
        $this->assertEquals('executed', $result->get('first'));
        $this->assertEquals('data', $result->get('second'));
    }

    public function test_concurrent_workflow_execution(): void
    {

        $workflow1 = Workflow::make()->addNodes([new AsyncDelayNode()]);
        $workflow2 = Workflow::make()->addNodes([new AsyncDelayNode()]);
        $workflow3 = Workflow::make()->addNodes([new AsyncDelayNode()]);

        $startTime = microtime(true);

        [$result1, $result2, $result3] = Future\await([
            async(fn (): \NeuronAI\Workflow\WorkflowState => $this->execute($workflow1)),
            async(fn (): \NeuronAI\Workflow\WorkflowState => $this->execute($workflow2)),
            async(fn (): \NeuronAI\Workflow\WorkflowState => $this->execute($workflow3)),
        ]);

        $duration = microtime(true) - $startTime;

        $this->assertTrue($result1->get('completed'));
        $this->assertTrue($result2->get('completed'));
        $this->assertTrue($result3->get('completed'));

        $this->assertLessThan(0.3, $duration, 'Concurrent execution should be faster than sequential');
    }

    public function test_workflow_state_preservation(): void
    {
        $state = new WorkflowState(['initial' => 'value']);

        $workflow = Workflow::make(state: $state)
            ->addNodes([
                new FirstNode(),
                new SecondNode(),
            ]);

        $result = async(fn (): \NeuronAI\Workflow\WorkflowState => $this->execute($workflow))->await();

        $this->assertEquals('value', $result->get('initial'));
        $this->assertEquals('executed', $result->get('first'));
        $this->assertEquals('data', $result->get('second'));
    }
}
