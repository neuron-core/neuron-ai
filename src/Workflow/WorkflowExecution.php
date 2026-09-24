<?php

declare(strict_types=1);

namespace NeuronAI\Workflow;

use NeuronAI\Observability\ExecutionEventDispatcher;
use NeuronAI\Workflow\Middleware\WorkflowMiddleware;
use Psr\EventDispatcher\EventDispatcherInterface;
use NeuronAI\Exceptions\WorkflowException;
use NeuronAI\Workflow\Events\Event;
use NeuronAI\Workflow\Streaming\Adapter\StreamAdapterInterface;
use NeuronAI\Workflow\Streaming\Channel\StreamingChannelInterface;
use NeuronAI\Workflow\Streaming\SegmentOutput;

use function array_merge;

/** One admitted segment's live graph, state and output resources. */
class WorkflowExecution implements WorkflowRuntimeInterface
{
    /** @var array<class-string, NodeInterface> */
    protected array $eventNodeMap = [];
    protected Event $startEvent;
    protected EventDispatcherInterface $dispatcher;
    protected bool $retainCompletion;
    protected SegmentOutput $output;

    /** @param array<class-string<NodeInterface>, array<WorkflowMiddleware>> $middleware */
    public function __construct(
        public readonly ExecutionContext $context,
        public readonly Workflow $definition,
        protected WorkflowState $state,
        protected WorkflowResources $resources,
        protected array $middleware = [],
        protected array $globalMiddleware = [],
    ) {
        $this->dispatcher = new ExecutionEventDispatcher($definition->getEventDispatcher(), $context);
        $this->retainCompletion = $definition->shouldRetainCompletionUntilAcknowledged();
        $this->startEvent = $context->startEvent();
        $this->state->markAsRunning();
        $this->state->setExecutionMetadata($context->workflowId, $context->runId, $context->executionAttempt);
    }

    /** @param NodeInterface[] $nodes */
    public function bootstrap(array $nodes): void
    {
        $signature = new NodeSignature();
        foreach ($nodes as $node) {
            $eventClass = $signature->eventClass($node, $this->resources);
            if (isset($this->eventNodeMap[$eventClass])) {
                throw new WorkflowException("Node for event {$eventClass} already exists");
            }
            $this->eventNodeMap[$eventClass] = $node;
        }
        if (!isset($this->eventNodeMap[$this->startEvent::class])) {
            throw new WorkflowException('No nodes found that handle ' . $this->startEvent::class);
        }
    }

    public function setOutput(?StreamAdapterInterface $adapter, ?StreamingChannelInterface $channel): void
    {
        $this->output = new SegmentOutput($adapter, $channel, $this->dispatcher, $this->definition, $this->context->workflowId);
    }

    public function getOutput(): SegmentOutput
    {
        return $this->output;
    }

    public function getState(): WorkflowState
    {
        return $this->state;
    }

    public function setState(WorkflowState $state): static
    {
        $this->state = $state;
        return $this;
    }

    public function getStartEvent(): Event
    {
        return $this->startEvent;
    }

    public function getResources(): WorkflowResources
    {
        return $this->resources;
    }

    public function getWorkflowId(): string
    {
        return $this->context->workflowId;
    }

    public function getRunId(): string
    {
        return $this->context->runId;
    }

    public function getEventDispatcher(): EventDispatcherInterface
    {
        return $this->dispatcher;
    }

    public function shouldRetainCompletionUntilAcknowledged(): bool
    {
        return $this->retainCompletion;
    }

    public function getEventNodeMap(): array
    {
        return $this->eventNodeMap;
    }

    public function getMiddlewareForNode(NodeInterface $node): array
    {
        $middleware = $this->globalMiddleware;
        foreach ($this->middleware as $class => $list) {
            if ($node instanceof $class) {
                $middleware = array_merge($middleware, $list);
            }
        }
        return $middleware;
    }

    public function getNodeForEvent(string $eventClass): NodeInterface
    {
        if (!isset($this->eventNodeMap[$eventClass])) {
            throw new WorkflowException(
                "No node found that handle event: " . $eventClass
            );
        }

        return $this->eventNodeMap[$eventClass];
    }
}
