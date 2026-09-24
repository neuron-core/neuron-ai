<?php

declare(strict_types=1);

namespace NeuronAI\Workflow;

use NeuronAI\Workflow\Events\Event;
use NeuronAI\Workflow\Middleware\WorkflowMiddleware;
use NeuronAI\Workflow\Streaming\SegmentOutput;
use Psr\EventDispatcher\EventDispatcherInterface;

/** Live segment capabilities consumed by traversal. Implemented by WorkflowExecution. @internal */
interface WorkflowRuntimeInterface
{
    public function getStartEvent(): Event;
    public function getState(): WorkflowState;
    public function getResources(): WorkflowResources;
    public function setState(WorkflowState $state): static;
    public function getNodeForEvent(string $eventClass): NodeInterface;
    /** @return array<class-string, NodeInterface> */
    public function getEventNodeMap(): array;
    /** @return WorkflowMiddleware[] */
    public function getMiddlewareForNode(NodeInterface $node): array;
    public function getWorkflowId(): string;
    public function getRunId(): string;
    public function getEventDispatcher(): EventDispatcherInterface;
    public function shouldRetainCompletionUntilAcknowledged(): bool;
    public function getOutput(): SegmentOutput;
}
