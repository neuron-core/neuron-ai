<?php

declare(strict_types=1);

namespace NeuronAI\Workflow;

use NeuronAI\Exceptions\WorkflowException;
use NeuronAI\Workflow\Executor\WorkflowExecutor;
use NeuronAI\Workflow\Executor\WorkflowExecutorInterface;
use NeuronAI\Workflow\Exporter\ExporterInterface;
use NeuronAI\Workflow\Exporter\WorkflowGraphBuilder;
use NeuronAI\Workflow\Persistence\InMemoryPersistence;
use NeuronAI\Workflow\Persistence\PersistenceInterface;
use NeuronAI\Workflow\Persistence\PhpSerializer;
use NeuronAI\Workflow\Persistence\Serializer;
use NeuronAI\Workflow\Streaming\Adapter\StreamAdapterInterface;
use NeuronAI\Workflow\Streaming\Channel\StreamingChannelInterface;
use Closure;

/**
 * Definitions hold configured services and resource recipes. Persistence and
 * serializer defaults are shared services; output factories resolve per segment. An executor
 * carries no configuration of its own, so choosing an execution model never
 * affects where state lives.
 */
trait HandleComponents
{
    protected ?WorkflowExecutorInterface $executor = null;

    protected ?PersistenceInterface $persistence = null;

    protected ?Serializer $serializer = null;

    protected ?int $leaseTimeout = null;

    protected bool $leaseTimeoutConfigured = false;

    protected bool $retainCompletion = false;

    protected StreamingChannelInterface|Closure|null $channel = null;

    /** Optional transform from native stream objects to protocol events. */
    protected StreamAdapterInterface|Closure|null $streamAdapter = null;

    protected ExporterInterface $exporter;

    final protected function getExecutor(): WorkflowExecutorInterface
    {
        return $this->executor === null ? $this->executor() : clone $this->executor;
    }

    protected function executor(): WorkflowExecutorInterface
    {
        return new WorkflowExecutor();
    }

    public function setExecutor(WorkflowExecutorInterface $executor): static
    {
        $this->executor = $executor;
        return $this;
    }

    /**
     * Enable durability by providing a persistence backend.
     */
    public function setPersistence(PersistenceInterface $persistence): static
    {
        $this->persistence = $persistence;
        return $this;
    }

    final public function getPersistence(): PersistenceInterface
    {
        return $this->persistence ??= $this->persistence();
    }

    protected function persistence(): PersistenceInterface
    {
        return new InMemoryPersistence();
    }

    /**
     * The codec for this run's durable records. It must be stable across
     * suspend/resume: records are read back with the configured codec.
     */
    public function setSerializer(Serializer $serializer): static
    {
        $this->serializer = $serializer;
        return $this;
    }

    final public function getSerializer(): Serializer
    {
        return $this->serializer ??= $this->serializer();
    }

    protected function serializer(): Serializer
    {
        return new PhpSerializer();
    }

    /**
     * Where in-flight output is delivered (a websocket, a broadcast, ...).
     * Content needs a stream adapter: without one the channel receives only
     * the segment lifecycle. Null falls back to the channel hook.
     * Changing this setting leaves an execution's resolved channel unchanged.
     */
    public function setChannel(StreamingChannelInterface|Closure|null $channel): static
    {
        $this->channel = $channel;
        return $this;
    }

    final protected function resolveChannel(ExecutionContext $context): ?StreamingChannelInterface
    {
        return $this->channel instanceof Closure ? ($this->channel)($context) : ($this->channel ?? $this->channel($context));
    }

    protected function channel(ExecutionContext $context): ?StreamingChannelInterface
    {
        return null;
    }

    /**
     * Attach the stream transform used by both pull iteration and channel
     * delivery. Adapter and channel compose — the adapter decides the shape,
     * the channel the destination. An adapter is stateful for one stream.
     * Changing this setting leaves an execution's resolved adapter unchanged.
     */
    public function setStreamAdapter(StreamAdapterInterface|Closure|null $adapter): static
    {
        $this->streamAdapter = $adapter;
        return $this;
    }

    final protected function resolveStreamAdapter(ExecutionContext $context): ?StreamAdapterInterface
    {
        return $this->streamAdapter instanceof Closure ? ($this->streamAdapter)($context) : ($this->streamAdapter ?? $this->streamAdapter($context));
    }

    protected function streamAdapter(ExecutionContext $context): ?StreamAdapterInterface
    {
        return null;
    }

    /**
     * @throws WorkflowException
     */
    public function export(?ExecutionContext $context = null): string
    {
        $context ??= new ExecutionContext($this->getWorkflowId() ?? 'preview', 'preview', 0, $this->makeIgnition('preview', $this->getStartEvent()));
        $execution = $this->buildGraph($context);
        return $this->exporter->export((new WorkflowGraphBuilder())->build($execution->getStartEvent()::class, $execution->getEventNodeMap()));
    }

    public function setExporter(ExporterInterface $exporter): static
    {
        $this->exporter = $exporter;
        return $this;
    }
}
