<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Execution;

use NeuronAI\Tests\Workflow\Stubs\NodeOne;
use NeuronAI\Tests\Workflow\Stubs\NodeTwo;
use NeuronAI\Tests\Workflow\Stubs\NodeThree;
use NeuronAI\Workflow\Events\Event;
use NeuronAI\Workflow\Events\StartEvent;
use NeuronAI\Workflow\Events\StopEvent;
use NeuronAI\Workflow\Execution\AsyncExecutor;
use NeuronAI\Workflow\Execution\SequentialExecutor;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\Node\ParallelNode;
use NeuronAI\Workflow\Workflow;
use NeuronAI\Workflow\WorkflowState;
use PHPUnit\Framework\TestCase;

use function Amp\async;
use function Amp\delay;
use function microtime;
use function class_exists;

// Test events
class TextProcessEvent implements Event
{
}
class ImageProcessEvent implements Event
{
}
class MergeEvent implements Event
{
}

// Test nodes
class TextProcessNode extends Node
{
    public function __invoke(TextProcessEvent $event, WorkflowState $state): StopEvent
    {
        $state->set('processedText', 'HELLO');
        return new StopEvent();
    }
}

class ImageProcessNode extends Node
{
    public function __invoke(ImageProcessEvent $event, WorkflowState $state): StopEvent
    {
        $state->set('processedImage', 'processed_image.jpg');
        return new StopEvent();
    }
}

class ParallelExecutorTest extends TestCase
{
    public function testSequentialExecutorIsDefault(): void
    {
        $workflow = Workflow::make()
            ->addNodes([
                new NodeOne(),
                new NodeTwo(),
                new NodeThree(),
            ]);

        $result = $workflow->init()->run();

        $this->assertTrue($result->get('node_one_executed'));
        $this->assertTrue($result->get('node_two_executed'));
        $this->assertTrue($result->get('node_three_executed'));
    }

    public function testParallelExecutorWithCustomExecutor(): void
    {
        $workflow = Workflow::make()
            ->setExecutor(new AsyncExecutor())
            ->addNodes([
                new NodeOne(),
                new NodeTwo(),
                new NodeThree(),
            ]);

        $result = async(fn () => $workflow->init()->run())->await();

        $this->assertTrue($result->get('node_one_executed'));
        $this->assertTrue($result->get('node_two_executed'));
        $this->assertTrue($result->get('node_three_executed'));
    }

    public function testParallelExecutionTiming(): void
    {
        // Test that parallel execution of independent delays is faster than sequential
        $delayNode1 = new class () extends Node {
            public function __invoke(StartEvent $event, WorkflowState $state): StopEvent
            {
                delay(0.15); // 150ms delay
                $state->set('delay1', true);
                return new StopEvent();
            }
        };

        $delayNode2 = new class () extends Node {
            public function __invoke(StartEvent $event, WorkflowState $state): StopEvent
            {
                delay(0.15); // 150ms delay
                $state->set('delay2', true);
                return new StopEvent();
            }
        };

        // Run two workflows with delays in parallel
        $workflow1 = Workflow::make()->addNodes([$delayNode1]);
        $workflow2 = Workflow::make()->addNodes([$delayNode2]);

        $start = microtime(true);
        [$result1, $result2] = \Amp\Future\await([
            async(fn () => $workflow1->init()->run()),
            async(fn () => $workflow2->init()->run()),
        ]);
        $duration = microtime(true) - $start;

        // With parallel execution, two 150ms delays should complete in ~150ms + overhead
        // Sequential would take ~300ms
        $this->assertLessThan(
            0.25,
            $duration,
            "Parallel execution should complete in ~150ms, not 300ms (sequential). Actual: {$duration}s"
        );
        $this->assertTrue($result1->get('delay1'));
        $this->assertTrue($result2->get('delay2'));
    }

    public function testSetExecutorMethod(): void
    {
        $workflow = Workflow::make()
            ->setExecutor(new AsyncExecutor())
            ->addNodes([
                new NodeOne(),
                new NodeTwo(),
                new NodeThree(),
            ]);

        $result = async(fn () => $workflow->init()->run())->await();

        $this->assertTrue($result->get('node_one_executed'));
        $this->assertTrue($result->get('node_two_executed'));
        $this->assertTrue($result->get('node_three_executed'));
    }

    public function testParallelNodeClassExists(): void
    {
        $this->assertTrue(class_exists(ParallelNode::class));
    }

    public function testParallelExecutorClassExists(): void
    {
        $this->assertTrue(class_exists(AsyncExecutor::class));
    }

    public function testSequentialExecutorClassExists(): void
    {
        $this->assertTrue(class_exists(SequentialExecutor::class));
    }
}
