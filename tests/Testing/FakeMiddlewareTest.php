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
use NeuronAI\Workflow\WorkflowResources;
use NeuronAI\Workflow\WorkflowState;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\DataProvider;
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

        $middleware->before($node, $event, $state, new WorkflowResources());
        $middleware->after($node, $result, $state, new WorkflowResources());

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
        $resources = new WorkflowResources();
        $middleware = FakeMiddleware::make()
            ->setBeforeHandler(function (NodeInterface $node, Event $event, WorkflowState $state, WorkflowResources $resources): void {
                $state->set('before', $node::class);
                $state->set('resources', $resources);
            })
            ->setAfterHandler(function (NodeInterface $node, Event $result, WorkflowState $state): void {
                $state->set('after', $result::class);
            });

        $middleware->before(new NodeOne(), new StartEvent(), $state, $resources);
        $middleware->after(new NodeOne(), new FirstEvent('done'), $state, $resources);

        $this->assertSame(NodeOne::class, $state->get('before'));
        $this->assertSame($resources, $state->get('resources'));
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
            $middleware->before(new NodeOne(), new StartEvent(), $state, new WorkflowResources());
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

        $middleware->after(new NodeOne(), new FirstEvent('done'), new WorkflowState(), new WorkflowResources());
    }

    public function test_call_assertions_pass(): void
    {
        $middleware = new FakeMiddleware();
        $middleware->assertNotCalled();
        $middleware->assertBeforeNotCalled();
        $middleware->assertAfterNotCalled();

        $middleware->before(new NodeOne(), new StartEvent(), new WorkflowState(), new WorkflowResources());
        $middleware->before(new NodeTwo(), new FirstEvent('first'), new WorkflowState(), new WorkflowResources());
        $middleware->after(new NodeTwo(), new SecondEvent('second'), new WorkflowState(), new WorkflowResources());

        $middleware->assertBeforeCalled();
        $middleware->assertAfterCalled();
        $middleware->assertBeforeCalledTimes(2);
        $middleware->assertAfterCalledTimes(1);
        $middleware->assertCallCount(3);
        $middleware->assertBeforeCalledForNode(NodeOne::class);
        $middleware->assertAfterCalledForNode(NodeTwo::class);
        $this->addToAssertionCount(1);
    }

    public function test_throw_on_after_runs_after_recording_and_handler(): void
    {
        $exception = new RuntimeException('after failed');
        $state = new WorkflowState();
        $middleware = FakeMiddleware::make()
            ->setAfterHandler(function (NodeInterface $node, Event $result, WorkflowState $state): void {
                $state->set('handled', true);
            })
            ->setThrowOnAfter($exception);

        try {
            $middleware->after(new NodeOne(), new FirstEvent('done'), $state, new WorkflowResources());
            $this->fail('Expected the configured exception.');
        } catch (RuntimeException $caught) {
            $this->assertSame($exception, $caught);
        }

        $this->assertTrue($state->get('handled'));
        $middleware->assertAfterCalledTimes(1);
        $middleware->assertBeforeNotCalled();
    }

    /**
     * Recorded calls: before(NodeOne), after(NodeOne). Every assertion below
     * contradicts them and must fail with its own message.
     *
     * @return iterable<string, array{string, array<mixed>, string}>
     */
    public static function unmetExpectations(): iterable
    {
        yield 'not called' => ['assertNotCalled', [], 'Expected middleware not to be called, but it was called 2 time(s).'];
        yield 'before not called' => ['assertBeforeNotCalled', [], 'Expected before() not to be called, but it was called 1 time(s).'];
        yield 'after not called' => ['assertAfterNotCalled', [], 'Expected after() not to be called, but it was called 1 time(s).'];
        yield 'before times' => ['assertBeforeCalledTimes', [2], 'Expected before() to be called 2 time(s), but it was called 1 time(s).'];
        yield 'after times' => ['assertAfterCalledTimes', [0], 'Expected after() to be called 0 time(s), but it was called 1 time(s).'];
        yield 'call count' => ['assertCallCount', [1], 'Expected 1 total middleware calls, got 2.'];
        yield 'before for node' => ['assertBeforeCalledForNode', [NodeTwo::class], 'Expected before() to be called for node ' . NodeTwo::class . ', but it was not.'];
        yield 'after for node' => ['assertAfterCalledForNode', [NodeTwo::class], 'Expected after() to be called for node ' . NodeTwo::class . ', but it was not.'];
    }

    /**
     * @param array<mixed> $arguments
     */
    #[DataProvider('unmetExpectations')]
    public function test_call_assertions_fail_when_unmet(string $assertion, array $arguments, string $message): void
    {
        $middleware = new FakeMiddleware();
        $middleware->before(new NodeOne(), new StartEvent(), new WorkflowState(), new WorkflowResources());
        $middleware->after(new NodeOne(), new FirstEvent('done'), new WorkflowState(), new WorkflowResources());

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage($message);

        $middleware->{$assertion}(...$arguments);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function callExpectations(): iterable
    {
        yield 'before' => ['assertBeforeCalled'];
        yield 'after' => ['assertAfterCalled'];
    }

    #[DataProvider('callExpectations')]
    public function test_call_assertions_fail_when_never_called(string $assertion): void
    {
        $this->expectException(AssertionFailedError::class);

        (new FakeMiddleware())->{$assertion}();
    }

    public function test_node_assertions_distinguish_before_from_after(): void
    {
        $middleware = new FakeMiddleware();
        $middleware->before(new NodeOne(), new StartEvent(), new WorkflowState(), new WorkflowResources());

        $this->expectException(AssertionFailedError::class);
        $middleware->assertAfterCalledForNode(NodeOne::class);
    }
}
