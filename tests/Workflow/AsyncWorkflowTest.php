<?php

declare(strict_types=1);

namespace Tests\Workflow;

use Amp\Future;
use NeuronAI\Workflow\Async\AmpWorkflowExecutor;
use NeuronAI\Workflow\Events\Event;
use NeuronAI\Workflow\Events\StartEvent;
use NeuronAI\Workflow\Events\StopEvent;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\Workflow;
use NeuronAI\Workflow\WorkflowState;
use PHPUnit\Framework\TestCase;

use function Amp\async;
use function Amp\delay;
use function microtime;

class ProcessEvent implements Event
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
        // Use Amp's async delay - suspends Fiber during wait
        delay(0.1);
        $state->set('completed', true);
        return new StopEvent();
    }
}

class AsyncWorkflowTest extends TestCase
{
    public function testBasicAsyncExecution(): void
    {
        $workflow = Workflow::make()
            ->addNodes([
                new FirstNode(),
                new SecondNode(),
            ]);

        $executor = new AmpWorkflowExecutor();
        $future = $executor->execute($workflow);

        $result = $future->await();

        $this->assertInstanceOf(WorkflowState::class, $result);
        $this->assertEquals('executed', $result->get('first'));
        $this->assertEquals('data', $result->get('second'));
    }

    public function testConcurrentWorkflowExecution(): void
    {
        $workflow1 = Workflow::make()->addNodes([new AsyncDelayNode()]);
        $workflow2 = Workflow::make()->addNodes([new AsyncDelayNode()]);
        $workflow3 = Workflow::make()->addNodes([new AsyncDelayNode()]);

        $executor = new AmpWorkflowExecutor();

        $startTime = microtime(true);

        // Execute three workflows concurrently
        $future1 = $executor->execute($workflow1);
        $future2 = $executor->execute($workflow2);
        $future3 = $executor->execute($workflow3);

        [$result1, $result2, $result3] = Future\await([$future1, $future2, $future3]);

        $duration = microtime(true) - $startTime;

        // All three workflows should complete
        $this->assertTrue($result1->get('completed'));
        $this->assertTrue($result2->get('completed'));
        $this->assertTrue($result3->get('completed'));

        // Concurrent execution should take ~0.1s, not 0.3s (sequential)
        // Allow some overhead for test execution
        $this->assertLessThan(0.2, $duration, 'Concurrent execution should be faster than sequential');
    }

    public function testWorkflowStatePreservation(): void
    {
        $state = new WorkflowState(['initial' => 'value']);

        $workflow = Workflow::make($state)
            ->addNodes([
                new FirstNode(),
                new SecondNode(),
            ]);

        $executor = new AmpWorkflowExecutor();
        $result = $executor->execute($workflow)->await();

        $this->assertEquals('value', $result->get('initial'));
        $this->assertEquals('executed', $result->get('first'));
        $this->assertEquals('data', $result->get('second'));
    }
}
