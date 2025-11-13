<?php

declare(strict_types=1);

namespace NeuronAI\Agent;

use Inspector\Exceptions\InspectorException;
use NeuronAI\Agent\Events\AIInferenceEvent;
use NeuronAI\Agent\Nodes\ChatNode;
use NeuronAI\Agent\Nodes\ParallelToolNode;
use NeuronAI\Agent\Nodes\StreamingNode;
use NeuronAI\Agent\Nodes\StructuredOutputNode;
use NeuronAI\Agent\Nodes\ToolNode;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Exceptions\AgentException;
use NeuronAI\Exceptions\WorkflowException;
use NeuronAI\HandleContent;
use NeuronAI\Chat\Messages\Stream\Adapters\StreamAdapterInterface;
use NeuronAI\Workflow\Events\Event;
use NeuronAI\Workflow\Interrupt\InterruptRequest;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\Workflow;
use NeuronAI\Workflow\WorkflowInterrupt;

/**
 * @method AgentState resolveState()
 */
class Agent extends Workflow implements AgentInterface
{
    use ResolveState;
    use ResolveProvider;
    use HandleTools;
    use HandleContent;
    use ChatHistoryHelper;

    protected string $instructions;

    protected bool $parallelToolCalls = false;

    protected function instructions(): string
    {
        return 'Your are a helpful and friendly AI agent built with Neuron PHP framework.';
    }

    public function setInstructions(string $instructions): self
    {
        $this->instructions = $instructions;
        return $this;
    }

    public function resolveInstructions(): string
    {
        return $this->instructions ?? $this->instructions();
    }

    /**
     * Determines whether tools should be executed in parallel.
     * Override this method to return true to enable parallel tool execution.
     *
     * Note: Parallel execution requires the pcntl extension and spatie/fork package.
     */
    public function parallelToolCalls(?bool $flag = null): bool|AgentInterface
    {
        if ($flag === null) {
            return $this->parallelToolCalls;
        }
        $this->parallelToolCalls = $flag;
        return $this;
    }

    /**
     * Prepare the agent workflow with mode-specific nodes.
     * Since Agent extends Workflow, we configure the current instance.
     *
     * @param Node|Node[] $nodes Mode-specific nodes (ChatNode, StreamingNode, etc.)
     */
    protected function compose(array|Node $nodes): void
    {
        if ($this->eventNodeMap !== []) {
            // Already composed, do nothing
            return;
        }

        $nodes = \is_array($nodes) ? $nodes : [$nodes];

        // Select the appropriate ToolNode based on the parallel execution setting
        $toolNode = $this->parallelToolCalls()
            ? new ParallelToolNode($this->toolMaxTries)
            : new ToolNode($this->toolMaxTries);

        // Add nodes to this workflow instance
        $this->addNodes([
            ...$nodes,
            $toolNode,
        ]);
    }

    protected function startEvent(): Event
    {
        return new AIInferenceEvent(
            $this->resolveInstructions(),
            $this->bootstrapTools()
        );
    }

    /**
     * @param Message|Message[] $messages
     * @throws \Throwable
     * @throws WorkflowInterrupt
     * @throws InspectorException
     * @throws WorkflowException
     */
    public function chat(Message|array $messages = [], ?InterruptRequest $interrupt = null): Message
    {
        $this->emit('chat-start');

        $messages = \is_array($messages) ? $messages : [$messages];
        foreach ($messages as $message) {
            $this->addToChatHistory($this->resolveState(), $message);
        }

        // Prepare workflow nodes for chat mode
        $this->compose(
            new ChatNode($this->resolveProvider()),
        );

        // Start workflow execution (Agent IS the workflow)
        $handler = parent::start($interrupt);

        /** @var AgentState $finalState */
        $finalState = $handler->getResult();

        $this->emit('chat-stop');

        return $finalState->getChatHistory()->getLastMessage();
    }

    /**
     * @param Message|Message[] $messages
     * @throws WorkflowInterrupt
     * @throws InspectorException
     * @throws WorkflowException
     * @throws \Throwable
     */
    public function stream(Message|array $messages = [], ?InterruptRequest $interrupt = null): \Generator
    {
        $this->emit('stream-start');

        $messages = \is_array($messages) ? $messages : [$messages];
        foreach ($messages as $message) {
            $this->addToChatHistory($this->resolveState(), $message);
        }

        // Prepare workflow nodes for streaming mode
        $this->compose(
            new StreamingNode($this->resolveProvider()),
        );

        // Start workflow execution (Agent IS the workflow)
        $handler = parent::start($interrupt);

        // Stream events and yield only StreamChunk objects
        foreach ($handler->streamEvents() as $event) {
            yield $event;
        }

        $this->emit('stream-stop');

        return $this->resolveState()->getChatHistory()->getLastMessage();
    }

    /**
     * Stream with a protocol adapter for frontend integration.
     *
     * @throws \Throwable
     * @throws WorkflowInterrupt
     * @throws InspectorException
     * @throws WorkflowException
     */
    public function streamWithAdapter(
        StreamAdapterInterface $adapter,
        Message|array $messages = [],
        ?InterruptRequest $interrupt = null
    ): \Generator {
        // Protocol start
        foreach ($adapter->start() as $output) {
            yield $output;
        }

        // Transform each chunk through the adapter
        foreach ($this->stream($messages, $interrupt) as $chunk) {
            foreach ($adapter->transform($chunk) as $output) {
                yield $output;
            }
        }

        // Protocol end
        foreach ($adapter->end() as $output) {
            yield $output;
        }

        // Preserve the return value from the original stream
        return $this->resolveState()->getChatHistory()->getLastMessage();
    }

    /**
     * @param Message|Message[]  $messages
     * @throws \Throwable
     * @throws AgentException
     * @throws WorkflowInterrupt
     * @throws InspectorException
     * @throws WorkflowException
     */
    public function structured(Message|array $messages = [], ?string $class = null, int $maxRetries = 1, ?InterruptRequest $interrupt = null): mixed
    {
        $this->emit('structured-start');

        $messages = \is_array($messages) ? $messages : [$messages];
        foreach ($messages as $message) {
            $this->addToChatHistory($this->resolveState(), $message);
        }

        // Get the output class
        $class ??= $this->getOutputClass();

        // Prepare workflow nodes for structured output mode
        $this->compose(
            new StructuredOutputNode($this->resolveProvider(), $class, $maxRetries),
        );

        // Start workflow execution (Agent IS the workflow)
        $handler = parent::start($interrupt);

        /** @var AgentState $finalState */
        $finalState = $handler->getResult();

        $this->emit('structured-stop');

        // Return the structured output object
        return $finalState->get('structured_output');
    }

    /**
     * Get the output class for structured output.
     * Override this method in subclasses to provide a default output class.
     *
     * @throws AgentException
     */
    protected function getOutputClass(): string
    {
        throw new AgentException('You need to specify an output class.');
    }
}
