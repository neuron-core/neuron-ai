<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow;

use NeuronAI\Exceptions\SkipRemainingMiddlewareException;
use NeuronAI\Workflow\Events\Event;
use NeuronAI\Workflow\Events\StartEvent;
use NeuronAI\Workflow\Events\StopEvent;
use NeuronAI\Workflow\Middleware\WorkflowMiddleware;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\NodeInterface;
use NeuronAI\Workflow\Workflow;
use NeuronAI\Workflow\WorkflowState;
use PHPUnit\Framework\TestCase;
use stdClass;

class SingleStepNode extends Node
{
    public function __invoke(StartEvent $event, WorkflowState $state): StopEvent
    {
        $state->set('node_executed', true);

        return new StopEvent('done');
    }
}

class SkipRemainingMiddlewareTest extends TestCase
{
    public function testSecondMiddlewareBeforeIsNotCalledWhenFirstThrowsSkip(): void
    {
        $tracker = new stdClass();
        $tracker->calls = [];

        $firstMiddleware = new class ($tracker) implements WorkflowMiddleware {
            public function __construct(private stdClass $tracker)
            {
            }

            public function before(NodeInterface $node, Event $event, WorkflowState $state): void
            {
                $this->tracker->calls[] = 'first.before';
                throw new SkipRemainingMiddlewareException('skip from first');
            }

            public function after(NodeInterface $node, Event $result, WorkflowState $state): void
            {
                $this->tracker->calls[] = 'first.after';
            }
        };

        $secondMiddleware = new class ($tracker) implements WorkflowMiddleware {
            public function __construct(private stdClass $tracker)
            {
            }

            public function before(NodeInterface $node, Event $event, WorkflowState $state): void
            {
                $this->tracker->calls[] = 'second.before';
            }

            public function after(NodeInterface $node, Event $result, WorkflowState $state): void
            {
                $this->tracker->calls[] = 'second.after';
            }
        };

        Workflow::make()
            ->addGlobalMiddleware([$firstMiddleware, $secondMiddleware])
            ->addNodes([new SingleStepNode()])
            ->init()
            ->run();

        $this->assertContains('first.before', $tracker->calls, 'First middleware before() should be called');
        $this->assertNotContains('second.before', $tracker->calls, 'Second middleware before() should NOT be called after skip');
    }

    public function testAfterMethodRunsForAllMiddlewareEvenAfterSkip(): void
    {
        $tracker = new stdClass();
        $tracker->calls = [];

        $firstMiddleware = new class ($tracker) implements WorkflowMiddleware {
            public function __construct(private stdClass $tracker)
            {
            }

            public function before(NodeInterface $node, Event $event, WorkflowState $state): void
            {
                $this->tracker->calls[] = 'first.before';
                throw new SkipRemainingMiddlewareException('skip from first');
            }

            public function after(NodeInterface $node, Event $result, WorkflowState $state): void
            {
                $this->tracker->calls[] = 'first.after';
            }
        };

        $secondMiddleware = new class ($tracker) implements WorkflowMiddleware {
            public function __construct(private stdClass $tracker)
            {
            }

            public function before(NodeInterface $node, Event $event, WorkflowState $state): void
            {
                $this->tracker->calls[] = 'second.before';
            }

            public function after(NodeInterface $node, Event $result, WorkflowState $state): void
            {
                $this->tracker->calls[] = 'second.after';
            }
        };

        Workflow::make()
            ->addGlobalMiddleware([$firstMiddleware, $secondMiddleware])
            ->addNodes([new SingleStepNode()])
            ->init()
            ->run();

        $this->assertContains('first.after', $tracker->calls, 'First middleware after() should still be called');
        $this->assertContains('second.after', $tracker->calls, 'Second middleware after() should still be called');
    }

    public function testSkipRemainingMiddlewareHasCorrectDefaultMessage(): void
    {
        $exception = new SkipRemainingMiddlewareException();

        $this->assertEquals('Skipping remaining middleware', $exception->getMessage());
    }

    public function testSkipRemainingMiddlewareAcceptsCustomMessage(): void
    {
        $exception = new SkipRemainingMiddlewareException('custom message');

        $this->assertEquals('custom message', $exception->getMessage());
    }

    public function testNormalMiddlewareChainIsUnaffectedWithoutSkip(): void
    {
        $tracker = new stdClass();
        $tracker->calls = [];

        $firstMiddleware = new class ($tracker) implements WorkflowMiddleware {
            public function __construct(private stdClass $tracker)
            {
            }

            public function before(NodeInterface $node, Event $event, WorkflowState $state): void
            {
                $this->tracker->calls[] = 'first.before';
            }

            public function after(NodeInterface $node, Event $result, WorkflowState $state): void
            {
                $this->tracker->calls[] = 'first.after';
            }
        };

        $secondMiddleware = new class ($tracker) implements WorkflowMiddleware {
            public function __construct(private stdClass $tracker)
            {
            }

            public function before(NodeInterface $node, Event $event, WorkflowState $state): void
            {
                $this->tracker->calls[] = 'second.before';
            }

            public function after(NodeInterface $node, Event $result, WorkflowState $state): void
            {
                $this->tracker->calls[] = 'second.after';
            }
        };

        Workflow::make()
            ->addGlobalMiddleware([$firstMiddleware, $secondMiddleware])
            ->addNodes([new SingleStepNode()])
            ->init()
            ->run();

        $this->assertContains('first.before', $tracker->calls);
        $this->assertContains('second.before', $tracker->calls);
        $this->assertContains('first.after', $tracker->calls);
        $this->assertContains('second.after', $tracker->calls);
    }
}
