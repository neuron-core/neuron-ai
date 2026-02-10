<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow;

use Amp\Future;
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
            ])
            ->init();

        $result = async(fn () => $workflow->run())->await();

        $this->assertInstanceOf(WorkflowState::class, $result);
        $this->assertEquals('executed', $result->get('first'));
        $this->assertEquals('data', $result->get('second'));
    }

    public function testConcurrentWorkflowExecution(): void
    {
        $workflow1 = Workflow::make()->addNodes([new AsyncDelayNode()])->init();
        $workflow2 = Workflow::make()->addNodes([new AsyncDelayNode()])->init();
        $workflow3 = Workflow::make()->addNodes([new AsyncDelayNode()])->init();

        $startTime = microtime(true);

        [$result1, $result2, $result3] = Future\await([
            async(fn () => $workflow1->run()),
            async(fn () => $workflow2->run()),
            async(fn () => $workflow3->run())
        ]);

        $duration = microtime(true) - $startTime;

        // All three workflows should complete
        $this->assertTrue($result1->get('completed'));
        $this->assertTrue($result2->get('completed'));
        $this->assertTrue($result3->get('completed'));

        // Concurrent execution should take ~0.1s, not 0.3s (sequential)
        // Allow some overhead for test execution
        $this->assertLessThan(0.3, $duration, 'Concurrent execution should be faster than sequential');
    }

    public function testWorkflowStatePreservation(): void
    {
        $state = new WorkflowState(['initial' => 'value']);

        $workflow = Workflow::make(state: $state)
            ->addNodes([
                new FirstNode(),
                new SecondNode(),
            ])
            ->init();

        $result = async(fn () => $workflow->run())->await();

        $this->assertEquals('value', $result->get('initial'));
        $this->assertEquals('executed', $result->get('first'));
        $this->assertEquals('data', $result->get('second'));
    }
}
