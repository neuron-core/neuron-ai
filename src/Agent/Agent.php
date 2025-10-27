<?php

declare(strict_types=1);

namespace NeuronAI\Agent;

use Inspector\Exceptions\InspectorException;
use NeuronAI\Agent\Events\AIInferenceEvent;
use NeuronAI\Agent\ChatHistoryHelper;
use NeuronAI\Agent\Nodes\ChatNode;
use NeuronAI\Agent\Nodes\ParallelToolNode;
use NeuronAI\Agent\Nodes\StreamingNode;
use NeuronAI\Agent\Nodes\StructuredOutputNode;
use NeuronAI\Agent\Nodes\ToolNode;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Exceptions\AgentException;
use NeuronAI\Exceptions\WorkflowException;
use NeuronAI\HandleContent;
use NeuronAI\Workflow\Events\Event;
use NeuronAI\Workflow\Interrupt\InterruptRequest;
use NeuronAI\Workflow\Middleware\WorkflowMiddleware;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\NodeInterface;
use NeuronAI\Workflow\Persistence\InMemoryPersistence;
use NeuronAI\Workflow\Persistence\PersistenceInterface;
use NeuronAI\Workflow\Workflow;
use NeuronAI\Workflow\WorkflowInterrupt;

class Agent extends Workflow implements AgentInterface
{
    use ResolveState;
    use ResolveProvider;
    use HandleTools;
    use HandleContent;
    use ChatHistoryHelper;

    protected string $instructions;

    protected bool $parallelToolCalls = false;

    /**
     * @throws WorkflowException
     */
    public function __construct(
        ?AgentState $state = null,
        ?PersistenceInterface $persistence = null,
        ?string $workflowId = null
    ) {
        // Initialize parent Workflow
        parent::__construct(
            $state ?? $this->agentState(),
            $persistence,
            $workflowId
        );

        $this->init();
    }

    /**
     * Initialize agent.
     *
     * @throws WorkflowException
     */
    private function init(): void
    {
        foreach ($this->agentMiddleware() as $nodeClass => $middlewares) {
            parent::addMiddleware($nodeClass, $middlewares);
        }
    }

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
     * Configure middleware for this agent when using the inheritance pattern.
     * Override this method to register middleware on specific nodes.
     *
     * @return array<class-string<NodeInterface>, WorkflowMiddleware|WorkflowMiddleware[]>
     */
    protected function agentMiddleware(): array
    {
        return [];
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
        $this->notify('chat-start');

        $messages = \is_array($messages) ? $messages : [$messages];
        foreach ($messages as $message) {
            $this->addToChatHistory($this->resolveAgentState(), $message);
        }

        // Prepare workflow nodes for chat mode
        $this->compose(
            new ChatNode($this->resolveProvider()),
        );

        // Start workflow execution (Agent IS the workflow)
        $handler = parent::start($interrupt);

        /** @var AgentState $finalState */
        $finalState = $handler->getResult();

        $this->notify('chat-stop');

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
        $this->notify('stream-start');

        $messages = \is_array($messages) ? $messages : [$messages];
        foreach ($messages as $message) {
            $this->addToChatHistory($this->resolveAgentState(), $message);
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

        /** @var AgentState $finalState */
        $finalState = $handler->getResult();

        $this->notify('stream-stop');

        return $finalState->getChatHistory()->getLastMessage();
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
        $this->notify('structured-start');

        $messages = \is_array($messages) ? $messages : [$messages];
        foreach ($messages as $message) {
            $this->addToChatHistory($this->resolveAgentState(), $message);
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

        $this->notify('structured-stop');

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
