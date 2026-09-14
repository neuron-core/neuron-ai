<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Testing;

use NeuronAI\Testing\FakeMiddleware;
use NeuronAI\Tests\Workflow\Stub\FirstEvent;
use NeuronAI\Tests\Workflow\Stub\NodeOne;
use NeuronAI\Tests\Workflow\Stub\NodeTwo;
use NeuronAI\Tests\Workflow\Stub\SecondEvent;
use NeuronAI\Workflow\Events\Event;
use NeuronAI\Workflow\Events\StartEvent;
use NeuronAI\Workflow\NodeInterface;
use NeuronAI\Workflow\WorkflowState;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class FakeMiddlewareTest extends TestCase
{
    public function test_records_before_and_after_calls(): void
    {
        $middleware = new FakeMiddleware();
        $node = new NodeOne();
        $event = new StartEvent();
        $result = new FirstEvent('done');
        $state = new WorkflowState();

        $middleware->before($node, $event, $state);
        $middleware->after($node, $result, $state);

        $recorded = $middleware->getRecorded();
        $this->assertCount(2, $recorded);
        $this->assertSame('before', $recorded[0]->method);
        $this->assertSame($event, $recorded[0]->event);
        $this->assertSame('after', $recorded[1]->method);
        $this->assertSame($result, $recorded[1]->event);
        $this->assertSame($node, $recorded[1]->node);
        $this->assertSame($state, $recorded[1]->state);
        $this->assertSame([$recorded[0]], $middleware->getBeforeRecords());
        $this->assertSame([$recorded[1]], $middleware->getAfterRecords());
    }

    public function test_handlers_receive_the_call_arguments(): void
    {
        $state = new WorkflowState();
        $middleware = FakeMiddleware::make()
            ->setBeforeHandler(function (NodeInterface $node, Event $event, WorkflowState $state): void {
                $state->set('before', $node::class);
            })
            ->setAfterHandler(function (NodeInterface $node, Event $result, WorkflowState $state): void {
                $state->set('after', $result::class);
            });

        $middleware->before(new NodeOne(), new StartEvent(), $state);
        $middleware->after(new NodeOne(), new FirstEvent('done'), $state);

        $this->assertSame(NodeOne::class, $state->get('before'));
        $this->assertSame(FirstEvent::class, $state->get('after'));
    }

    public function test_throw_on_before_runs_after_recording_and_handler(): void
    {
        $exception = new RuntimeException('before failed');
        $state = new WorkflowState();
        $middleware = FakeMiddleware::make()
            ->setBeforeHandler(function (NodeInterface $node, Event $event, WorkflowState $state): void {
                $state->set('handled', true);
            })
            ->setThrowOnBefore($exception);

        try {
            $middleware->before(new NodeOne(), new StartEvent(), $state);
            $this->fail('Expected the configured exception.');
        } catch (RuntimeException $caught) {
            $this->assertSame($exception, $caught);
        }

        $this->assertTrue($state->get('handled'));
        $middleware->assertBeforeCalledTimes(1);
    }

    public function test_throw_on_after(): void
    {
        $exception = new RuntimeException('after failed');
        $middleware = FakeMiddleware::make()->setThrowOnAfter($exception);

        $this->expectExceptionObject($exception);

        $middleware->after(new NodeOne(), new FirstEvent('done'), new WorkflowState());
    }

    public function test_call_assertions_pass(): void
    {
        $middleware = new FakeMiddleware();
        $middleware->assertNotCalled();
        $middleware->assertBeforeNotCalled();
        $middleware->assertAfterNotCalled();

        $middleware->before(new NodeOne(), new StartEvent(), new WorkflowState());
        $middleware->before(new NodeTwo(), new FirstEvent('first'), new WorkflowState());
        $middleware->after(new NodeTwo(), new SecondEvent('second'), new WorkflowState());

        $middleware->assertBeforeCalled();
        $middleware->assertAfterCalled();
        $middleware->assertBeforeCalledTimes(2);
        $middleware->assertAfterCalledTimes(1);
        $middleware->assertCallCount(3);
        $middleware->assertBeforeCalledForNode(NodeOne::class);
        $middleware->assertAfterCalledForNode(NodeTwo::class);
        $this->addToAssertionCount(1);
    }

    public function test_call_assertions_fail(): void
    {
        $middleware = new FakeMiddleware();
        $middleware->before(new NodeOne(), new StartEvent(), new WorkflowState());

        $this->expectException(AssertionFailedError::class);
        $middleware->assertAfterCalledForNode(NodeOne::class);
    }
}
