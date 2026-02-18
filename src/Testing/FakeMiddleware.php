<?php

declare(strict_types=1);

namespace NeuronAI\Testing;

use Closure;
use NeuronAI\StaticConstructor;
use NeuronAI\Workflow\Events\Event;
use NeuronAI\Workflow\Middleware\WorkflowMiddleware;
use NeuronAI\Workflow\NodeInterface;
use NeuronAI\Workflow\WorkflowState;
use PHPUnit\Framework\Assert;
use Throwable;

use function array_filter;
use function array_values;
use function count;

class FakeMiddleware implements WorkflowMiddleware
{
    use StaticConstructor;

    /** @var MiddlewareRecord[] */
    protected array $recorded = [];

    protected ?Throwable $throwOnBefore = null;

    protected ?Throwable $throwOnAfter = null;

    protected ?Closure $beforeHandler = null;

    protected ?Closure $afterHandler = null;

    /**
     * Configure an exception to throw when before() is called.
     */
    public function setThrowOnBefore(Throwable $exception): self
    {
        $this->throwOnBefore = $exception;
        return $this;
    }

    /**
     * Configure an exception to throw when after() is called.
     */
    public function setThrowOnAfter(Throwable $exception): self
    {
        $this->throwOnAfter = $exception;
        return $this;
    }

    /**
     * Set a custom handler for before().
     *
     * @param Closure(NodeInterface, Event, WorkflowState): void $handler
     */
    public function setBeforeHandler(Closure $handler): self
    {
        $this->beforeHandler = $handler;
        return $this;
    }

    /**
     * Set a custom handler for after().
     *
     * @param Closure(NodeInterface, Event, WorkflowState): void $handler
     */
    public function setAfterHandler(Closure $handler): self
    {
        $this->afterHandler = $handler;
        return $this;
    }

    public function before(NodeInterface $node, Event $event, WorkflowState $state): void
    {
        $this->recorded[] = new MiddlewareRecord('before', $node, $event, $state);

        if ($this->beforeHandler instanceof Closure) {
            ($this->beforeHandler)($node, $event, $state);
        }

        if ($this->throwOnBefore instanceof Throwable) {
            throw $this->throwOnBefore;
        }
    }

    public function after(NodeInterface $node, Event $result, WorkflowState $state): void
    {
        $this->recorded[] = new MiddlewareRecord('after', $node, $result, $state);

        if ($this->afterHandler instanceof Closure) {
            ($this->afterHandler)($node, $result, $state);
        }

        if ($this->throwOnAfter instanceof Throwable) {
            throw $this->throwOnAfter;
        }
    }

    /**
     * @return MiddlewareRecord[]
     */
    public function getRecorded(): array
    {
        return $this->recorded;
    }

    /**
     * @return MiddlewareRecord[]
     */
    public function getBeforeRecords(): array
    {
        return array_values(array_filter(
            $this->recorded,
            static fn (MiddlewareRecord $record): bool => $record->method === 'before'
        ));
    }

    /**
     * @return MiddlewareRecord[]
     */
    public function getAfterRecords(): array
    {
        return array_values(array_filter(
            $this->recorded,
            static fn (MiddlewareRecord $record): bool => $record->method === 'after'
        ));
    }

    // ----------------------------------------------------------------
    // PHPUnit Assertions
    // ----------------------------------------------------------------

    public function assertBeforeCalled(): void
    {
        Assert::assertNotEmpty(
            $this->getBeforeRecords(),
            'Expected before() to be called at least once, but it was not called.'
        );
    }

    public function assertBeforeNotCalled(): void
    {
        Assert::assertEmpty(
            $this->getBeforeRecords(),
            'Expected before() not to be called, but it was called ' . count($this->getBeforeRecords()) . ' time(s).'
        );
    }

    public function assertAfterCalled(): void
    {
        Assert::assertNotEmpty(
            $this->getAfterRecords(),
            'Expected after() to be called at least once, but it was not called.'
        );
    }

    public function assertAfterNotCalled(): void
    {
        Assert::assertEmpty(
            $this->getAfterRecords(),
            'Expected after() not to be called, but it was called ' . count($this->getAfterRecords()) . ' time(s).'
        );
    }

    public function assertBeforeCalledTimes(int $expected): void
    {
        $actual = count($this->getBeforeRecords());

        Assert::assertSame(
            $expected,
            $actual,
            "Expected before() to be called {$expected} time(s), but it was called {$actual} time(s)."
        );
    }

    public function assertAfterCalledTimes(int $expected): void
    {
        $actual = count($this->getAfterRecords());

        Assert::assertSame(
            $expected,
            $actual,
            "Expected after() to be called {$expected} time(s), but it was called {$actual} time(s)."
        );
    }

    /**
     * Assert before() was called at least once for a specific node class.
     *
     * @param class-string<NodeInterface> $nodeClass
     */
    public function assertBeforeCalledForNode(string $nodeClass): void
    {
        $matched = false;

        foreach ($this->getBeforeRecords() as $record) {
            if ($record->node instanceof $nodeClass) {
                $matched = true;
                break;
            }
        }

        Assert::assertTrue($matched, "Expected before() to be called for node {$nodeClass}, but it was not.");
    }

    /**
     * Assert after() was called at least once for a specific node class.
     *
     * @param class-string<NodeInterface> $nodeClass
     */
    public function assertAfterCalledForNode(string $nodeClass): void
    {
        $matched = false;

        foreach ($this->getAfterRecords() as $record) {
            if ($record->node instanceof $nodeClass) {
                $matched = true;
                break;
            }
        }

        Assert::assertTrue($matched, "Expected after() to be called for node {$nodeClass}, but it was not.");
    }

    public function assertNotCalled(): void
    {
        Assert::assertEmpty(
            $this->recorded,
            'Expected middleware not to be called, but it was called ' . count($this->recorded) . ' time(s).'
        );
    }

    public function assertCallCount(int $expected): void
    {
        Assert::assertCount(
            $expected,
            $this->recorded,
            "Expected {$expected} total middleware calls, got " . count($this->recorded) . '.'
        );
    }
}
