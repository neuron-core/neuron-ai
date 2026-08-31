<?php

declare(strict_types=1);

namespace NeuronAI\Workflow;

use NeuronAI\Chat\Messages\Stream\Adapters\StreamAdapterInterface;
use NeuronAI\Workflow\Channel\StreamingChannelInterface;
use NeuronAI\Workflow\Executor\WorkflowExecutor;
use NeuronAI\Workflow\Executor\WorkflowExecutorInterface;
use NeuronAI\Workflow\Persistence\InMemoryPersistence;
use NeuronAI\Workflow\Persistence\PersistenceInterface;
use NeuronAI\Workflow\Persistence\PhpSerializer;
use NeuronAI\Workflow\Persistence\Serializer;

/**
 * Each component pairs a setter with a memoizing getX() and a protected
 * default hook: subclasses override the hook, never the getter. An executor
 * carries no configuration of its own, so choosing an execution model never
 * affects where state lives.
 */
trait HandleComponents
{
    protected ?WorkflowExecutorInterface $executor = null;

    protected ?PersistenceInterface $persistence = null;

    protected ?Serializer $serializer = null;

    protected ?int $leaseTimeout = null;

    protected bool $retainCompletion = false;

    protected ?StreamingChannelInterface $channel = null;

    /**
     * Optional push-side delivery transform: yielded items become protocol
     * lines (sendLine()) instead of native chunks (send()).
     */
    protected ?StreamAdapterInterface $streamAdapter = null;

    final protected function getExecutor(): WorkflowExecutorInterface
    {
        return $this->executor ??= $this->executor();
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
     * Where in-flight output is delivered (a websocket, SSE sink, ...).
     * Null means no channel is attached and delivery is skipped entirely.
     */
    public function setChannel(?StreamingChannelInterface $channel): static
    {
        $this->channel = $channel;
        return $this;
    }

    final protected function getChannel(): ?StreamingChannelInterface
    {
        return $this->channel ??= $this->channel();
    }

    protected function channel(): ?StreamingChannelInterface
    {
        return null;
    }

    /**
     * Attach a push-side delivery transform: yielded items become protocol
     * lines delivered via sendLine() instead of native chunks via send().
     * Adapter and channel compose — the adapter decides the shape, the
     * channel the destination. The adapter is stateful; do not share one
     * instance with a pull consumer (Agent::stream($message, $adapter)).
     */
    public function setStreamAdapter(?StreamAdapterInterface $adapter): static
    {
        $this->streamAdapter = $adapter;
        return $this;
    }

    final protected function getStreamAdapter(): ?StreamAdapterInterface
    {
        return $this->streamAdapter ??= $this->streamAdapter();
    }

    protected function streamAdapter(): ?StreamAdapterInterface
    {
        return null;
    }
}
