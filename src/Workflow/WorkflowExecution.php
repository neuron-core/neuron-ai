<?php

declare(strict_types=1);

namespace NeuronAI\Workflow;

use NeuronAI\Observability\ExecutionEventDispatcher;
use NeuronAI\Workflow\Middleware\WorkflowMiddleware;
use Psr\EventDispatcher\EventDispatcherInterface;
use Closure;
use Generator;
use NeuronAI\Exceptions\WorkflowException;
use NeuronAI\Observability\Events\AgentError;
use NeuronAI\Observability\Events\ChannelError;
use NeuronAI\Workflow\Events\Event;
use NeuronAI\Workflow\Events\InterruptEvent;
use NeuronAI\Workflow\Streaming\Adapter\StreamAdapterInterface;
use NeuronAI\Workflow\Streaming\Channel\StreamingChannelInterface;
use Throwable;

use function array_merge;

/** One admitted segment's live graph, state and output resources. @internal */
class WorkflowExecution implements WorkflowRuntimeInterface
{
    /** @var array<class-string, NodeInterface> */
    protected array $eventNodeMap = [];
    protected Event $startEvent;
    protected EventDispatcherInterface $dispatcher;
    protected bool $retainCompletion;
    protected ?StreamAdapterInterface $streamAdapter = null;
    protected ?StreamingChannelInterface $channel = null;

    /** @param array<class-string<NodeInterface>, array<WorkflowMiddleware>> $middleware */
    public function __construct(
        public readonly ExecutionContext $context,
        public readonly Workflow $definition,
        protected WorkflowState $state,
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
            $eventClass = $signature->eventClass($node);
            if (isset($this->eventNodeMap[$eventClass])) {
                throw new WorkflowException("Node for event {$eventClass} already exists");
            }
            $this->eventNodeMap[$eventClass] = $node;
        }
        if (!isset($this->eventNodeMap[$this->startEvent::class])) {
            throw new WorkflowException('No nodes found that handle ' . $this->startEvent::class);
        }
        $this->startEvent = $this->restoreEvent($this->startEvent);
    }

    public function setOutput(?StreamAdapterInterface $adapter, ?StreamingChannelInterface $channel): void
    {
        $this->streamAdapter = $adapter;
        $this->channel = $channel;
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

    public function restoreEvent(Event $event): Event
    {
        return $this->definition->restoreEvent($event, $this->context);
    }

    public function restoreState(WorkflowState $state): WorkflowState
    {
        return $this->definition->restoreState($state, $this->context);
    }

    protected function getStreamAdapter(): ?StreamAdapterInterface
    {
        return $this->streamAdapter;
    }

    protected function getChannel(): ?StreamingChannelInterface
    {
        return $this->channel;
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

    public function streamExecution(Generator $generator, Closure $onFailure): Generator
    {
        $traversing = false;
        try {
            $this->getStreamAdapter()?->reset();
            yield from $this->adapterOutput(fn (StreamAdapterInterface $adapter): iterable => $adapter->start());
            $traversing = true;
            foreach ($generator as $item) {
                foreach ($this->streamOutput($item) as $output) {
                    yield $output;
                }
            }
        } catch (Throwable $e) {
            if ($traversing) {
                $this->abortExecution($generator, $e);
            }
            $onFailure($e);
            foreach ($this->adapterOutput(fn (StreamAdapterInterface $adapter): iterable => $adapter->error($e)) as $output) {
                yield $output;
            }
            $this->fireChannel(fn (StreamingChannelInterface $channel) => $channel->failed($e, $this->context->workflowId));
            throw $e;
        }

        $state = $generator->getReturn();
        if ($state->isInterrupted()) {
            foreach ($this->adapterOutput(fn (StreamAdapterInterface $adapter): iterable => $adapter->interrupt($state->getInterruptRequest())) as $output) {
                yield $output;
            }
            $this->fireChannel(fn (StreamingChannelInterface $channel) => $channel->interrupted(clone $state));

            return clone $state;
        }

        foreach ($this->adapterOutput(fn (StreamAdapterInterface $adapter): iterable => $adapter->end()) as $output) {
            yield $output;
        }

        if ($state->getStatus() === WorkflowStatus::Completed) {
            $this->fireChannel(fn (StreamingChannelInterface $channel) => $channel->completed($state, $this->context->workflowId));
        }

        return clone $state;
    }

    protected function abortExecution(Generator $generator, Throwable $e): void
    {
        if (!$generator->valid()) {
            return;
        }

        try {
            $generator->throw($e);
            while ($generator->valid()) {
                $generator->next();
            }
        } catch (Throwable) {
            // The executor rethrows the failure once the run is marked failed.
        }
    }

    protected function streamOutput(object $item): Generator
    {
        $adapter = $this->getStreamAdapter();
        if (!$adapter instanceof StreamAdapterInterface) {
            yield $item;
            return;
        }

        if ($item instanceof InterruptEvent) {
            return;
        }

        foreach ($adapter->transform($item) as $event) {
            $this->fireChannel(fn (StreamingChannelInterface $channel) => $channel->send($event));
            yield $event;
        }
    }

    protected function adapterOutput(Closure $callback): Generator
    {
        $adapter = $this->getStreamAdapter();
        if (!$adapter instanceof StreamAdapterInterface) {
            return;
        }

        foreach ($callback($adapter) as $event) {
            $this->fireChannel(fn (StreamingChannelInterface $channel) => $channel->send($event));
            yield $event;
        }
    }

    protected function fireChannel(Closure $callback): void
    {
        if (!$this->getChannel() instanceof StreamingChannelInterface) {
            return;
        }

        try {
            $callback($this->getChannel());
        } catch (Throwable $e) {
            $this->reportChannelError($e);
        }
    }

    protected function reportChannelError(Throwable $e): void
    {
        $event = new ChannelError($e);
        $event->source = $this;

        try {
            $this->getEventDispatcher()->dispatch($event);
        } catch (Throwable $listenerFailure) {
            $error = new AgentError($listenerFailure, false);
            $error->source = $this;

            try {
                $this->getEventDispatcher()->dispatch($error);
            } catch (Throwable) {
                // Monitoring failures must not change Workflow execution.
            }
        }
    }
}
