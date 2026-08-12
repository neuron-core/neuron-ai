<?php

declare(strict_types=1);

namespace NeuronAI\Workflow;

use NeuronAI\Chat\Messages\Stream\Adapters\StreamAdapterInterface;
use NeuronAI\Workflow\Channel\StreamingChannelInterface;
use NeuronAI\Workflow\Executor\NullScheduler;
use NeuronAI\Workflow\Executor\SchedulerInterface;
use NeuronAI\Workflow\Executor\WorkflowExecutor;
use NeuronAI\Workflow\Executor\WorkflowExecutorInterface;
use NeuronAI\Workflow\Persistence\InMemoryPersistence;
use NeuronAI\Workflow\Persistence\PersistenceInterface;
use NeuronAI\Workflow\Persistence\PhpSerializer;
use NeuronAI\Workflow\Persistence\Serializer;

trait HandleComponents
{
    /**
     * Get the executor, creating a default if none was configured. An
     * executor carries no configuration of its own — it reads persistence,
     * scheduler, and the definition from this workflow at execute() time — so
     * choosing an execution model never affects where state lives.
     */
    final protected function getExecutor(): WorkflowExecutorInterface
    {
        return $this->executor ??= $this->executor();
    }

    /**
     * Provide the default execution model. Subclasses override this hook —
     * never getExecutor(), which memoizes the resolved instance.
     */
    protected function executor(): WorkflowExecutorInterface
    {
        return new WorkflowExecutor();
    }

    /**
     * Set a custom executor for this workflow.
     */
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

    /**
     * The state store this run's durable records live in. The executor reads
     * it through the runtime contract.
     */
    final public function getPersistence(): PersistenceInterface
    {
        return $this->persistence ??= $this->persistence();
    }

    /**
     * Provide the default persistence backend. Subclasses override this hook —
     * never getPersistence(), which memoizes the resolved instance.
     */
    protected function persistence(): PersistenceInterface
    {
        return new InMemoryPersistence();
    }

    /**
     * Choose the codec this run's durable records are encoded with. The
     * serializer must be stable across suspend/resume: a run's records are
     * read back with the codec the workflow is configured with.
     */
    public function setSerializer(Serializer $serializer): static
    {
        $this->serializer = $serializer;
        return $this;
    }

    /**
     * The codec this run's durable records are encoded with. The workflow
     * owns the choice; the executor reads it through the runtime contract.
     */
    final public function getSerializer(): Serializer
    {
        return $this->serializer ??= $this->serializer();
    }

    /**
     * Provide the default record codec. Subclasses override this hook —
     * never getSerializer(), which memoizes the resolved instance.
     */
    protected function serializer(): Serializer
    {
        return new PhpSerializer();
    }

    /**
     * Provide a scheduler to coordinate wakeups for suspended workflows.
     *
     * Defaults to an inert NullScheduler (caller-driven resume), matching the
     * out-of-the-box behavior.
     */
    public function setScheduler(SchedulerInterface $scheduler): static
    {
        $this->scheduler = $scheduler;
        return $this;
    }

    /**
     * The coordinator for this workflow's suspends and wakeups. The executor
     * reads it through the runtime contract.
     */
    final public function getScheduler(): SchedulerInterface
    {
        return $this->scheduler ??= $this->scheduler();
    }

    /**
     * Provide the default scheduler. Subclasses override this hook —
     * never getScheduler(), which memoizes the resolved instance.
     */
    protected function scheduler(): SchedulerInterface
    {
        return new NullScheduler();
    }

    /**
     * Where in-flight output is delivered while the run is in flight (a
     * websocket, SSE sink, ...). Optional — null (the default) means no channel
     * is attached and delivery is skipped entirely.
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

    /**
     * Provide the default channel. Subclasses override this hook —
     * never getChannel(), which memoizes the resolved instance.
     */
    protected function channel(): ?StreamingChannelInterface
    {
        return null;
    }

    /**
     * Attach a stream adapter as the push-side delivery transform. When set,
     * the workflow runs each yielded item through the adapter and delivers the
     * resulting protocol lines (plus the adapter's start()/end() framing) to
     * the channel via sendLine(); when null (the default), native chunks are
     * delivered via send(). This is independent of the channel — the two
     * compose (adapter decides the shape, channel decides the destination).
     *
     * The adapter is stateful; do not share one instance between this and a
     * pull consumer (Agent::stream($message, $adapter)).
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

    /**
     * Provide the default stream adapter. Subclasses override this hook —
     * never getAdapter(), which memoizes the resolved instance.
     */
    protected function streamAdapter(): ?StreamAdapterInterface
    {
        return null;
    }
}
