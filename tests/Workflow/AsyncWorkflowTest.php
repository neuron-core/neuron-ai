<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow;

use Amp\Future;
use NeuronAI\Tests\Workflow\Executor\ExecutorTestHelpers;
use NeuronAI\Workflow\Events\Event;
use NeuronAI\Workflow\Events\StartEvent;
use NeuronAI\Workflow\Events\StopEvent;
use NeuronAI\Workflow\Executor\LocalStepEngine;
use NeuronAI\Workflow\Executor\WorkflowExecutor;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\Persistence\InMemoryPersistence;
use NeuronAI\Workflow\Workflow;
use NeuronAI\Workflow\WorkflowState;
use PHPUnit\Framework\TestCase;

use function Amp\async;
use function Amp\delay;
use function microtime;

class ProcessEvent extends Event
{
    public function __construct(public readonly string $value)
    {
    }
}

class FirstNode extends Node
{
    public function __invoke(StartEvent $event, WorkflowState $state): ProcessEvent
    {
        $state->set('first', 'executed');
        return new ProcessEvent('data');
    }
}

class SecondNode extends Node
{
    public function __invoke(ProcessEvent $event, WorkflowState $state): StopEvent
    {
        $state->set('second', $event->value);
        return new StopEvent();
    }
}

class AsyncDelayNode extends Node
{
    public function __invoke(StartEvent $event, WorkflowState $state): StopEvent
    {
        delay(0.1);
        $state->set('completed', true);
        return new StopEvent();
    }
}

class AsyncWorkflowTest extends TestCase
{
    use ExecutorTestHelpers;

    public function testBasicAsyncExecution(): void
    {
        $workflow = Workflow::make()
            ->addNodes([
                new FirstNode(),
                new SecondNode(),
            ]);

        $executor = new WorkflowExecutor(new LocalStepEngine(new InMemoryPersistence()));

        $result = async(fn (): \NeuronAI\Workflow\WorkflowState => $this->execute($workflow, $executor))->await();

        $this->assertInstanceOf(WorkflowState::class, $result);
        $this->assertEquals('executed', $result->get('first'));
        $this->assertEquals('data', $result->get('second'));
    }

    public function testConcurrentWorkflowExecution(): void
    {
        $executor = new WorkflowExecutor(new LocalStepEngine(new InMemoryPersistence()));

        $workflow1 = Workflow::make()->addNodes([new AsyncDelayNode()]);
        $workflow2 = Workflow::make()->addNodes([new AsyncDelayNode()]);
        $workflow3 = Workflow::make()->addNodes([new AsyncDelayNode()]);

        $startTime = microtime(true);

        [$result1, $result2, $result3] = Future\await([
            async(fn (): \NeuronAI\Workflow\WorkflowState => $this->execute($workflow1, $executor)),
            async(fn (): \NeuronAI\Workflow\WorkflowState => $this->execute($workflow2, $executor)),
            async(fn (): \NeuronAI\Workflow\WorkflowState => $this->execute($workflow3, $executor)),
        ]);

        $duration = microtime(true) - $startTime;

        $this->assertTrue($result1->get('completed'));
        $this->assertTrue($result2->get('completed'));
        $this->assertTrue($result3->get('completed'));

        $this->assertLessThan(0.3, $duration, 'Concurrent execution should be faster than sequential');
    }

    public function testWorkflowStatePreservation(): void
    {
        $state = new WorkflowState(['initial' => 'value']);
        $executor = new WorkflowExecutor(new LocalStepEngine(new InMemoryPersistence()));

        $workflow = Workflow::make(state: $state)
            ->addNodes([
                new FirstNode(),
                new SecondNode(),
            ]);

        $result = async(fn (): \NeuronAI\Workflow\WorkflowState => $this->execute($workflow, $executor))->await();

        $this->assertEquals('value', $result->get('initial'));
        $this->assertEquals('executed', $result->get('first'));
        $this->assertEquals('data', $result->get('second'));
    }
}
