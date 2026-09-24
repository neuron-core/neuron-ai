<?php

declare(strict_types=1);

namespace NeuronAI\Workflow;

use Closure;
use Generator;
use NeuronAI\Workflow\Events\Event;
use NeuronAI\Workflow\Middleware\WorkflowMiddleware;
use Psr\EventDispatcher\EventDispatcherInterface;
use Throwable;

/** Live segment capabilities consumed by traversal. Implemented by WorkflowExecution. @internal */
interface WorkflowRuntimeInterface
{
    public function getStartEvent(): Event;
    public function getState(): WorkflowState;
    public function setState(WorkflowState $state): static;
    public function getNodeForEvent(string $eventClass): NodeInterface;
    /** @return array<class-string, NodeInterface> */
    public function getEventNodeMap(): array;
    /** @return WorkflowMiddleware[] */
    public function getMiddlewareForNode(NodeInterface $node): array;
    public function getWorkflowId(): string;
    public function getRunId(): string;
    public function getEventDispatcher(): EventDispatcherInterface;
    public function restoreState(WorkflowState $state): WorkflowState;
    public function shouldRetainCompletionUntilAcknowledged(): bool;

    /**
     * @param Generator<int, object, mixed, WorkflowState> $generator
     * @param Closure(Throwable): void $onFailure
     * @return Generator<int, object, mixed, WorkflowState>
     */
    public function streamExecution(Generator $generator, Closure $onFailure): Generator;
}
