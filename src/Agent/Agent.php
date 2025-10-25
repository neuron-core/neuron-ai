<?php

declare(strict_types=1);

namespace NeuronAI\Agent;

use NeuronAI\Agent\Nodes\ChatNode;
use NeuronAI\Agent\Nodes\ParallelToolNode;
use NeuronAI\Agent\Nodes\PrepareInferenceNode;
use NeuronAI\Agent\Nodes\RouterNode;
use NeuronAI\Agent\Nodes\StreamingNode;
use NeuronAI\Agent\Nodes\StructuredOutputNode;
use NeuronAI\Agent\Nodes\ToolNode;
use NeuronAI\Chat\History\ChatHistoryInterface;
use NeuronAI\Chat\History\InMemoryChatHistory;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Exceptions\AgentException;
use NeuronAI\Exceptions\WorkflowException;
use NeuronAI\HandleContent;
use NeuronAI\Workflow\Interrupt\InterruptRequest;
use NeuronAI\Workflow\Middleware\WorkflowMiddleware;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\NodeInterface;
use NeuronAI\Workflow\Persistence\InMemoryPersistence;
use NeuronAI\Workflow\Persistence\PersistenceInterface;
use NeuronAI\Workflow\Workflow;
use Throwable;

class Agent extends Workflow implements AgentInterface
{
    use ResolveState;
    use ResolveProvider;
    use HandleTools;
    use HandleContent;

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
            $persistence ?? new InMemoryPersistence(),
            $workflowId ?? \uniqid('neuron_agent_')
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
            parent::middleware($nodeClass, $middlewares);
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
        // Clear any previously added nodes (important for multiple calls)
        $this->clearNodes();

        $nodes = \is_array($nodes) ? $nodes : [$nodes];

        // Select the appropriate ToolNode based on the parallel execution setting
        $toolNode = $this->parallelToolCalls()
            ? new ParallelToolNode($this->toolMaxTries)
            : new ToolNode($this->toolMaxTries);

        // Add nodes to this workflow instance
        $this->addNodes([
            ...$this->agentWorkflowNodes(),
            ...$nodes,
            new RouterNode(),
            $toolNode,
        ]);
    }

    /**
     * @return Node[]
     */
    protected function agentWorkflowNodes(): array
    {
        return [
            new PrepareInferenceNode(
                $this->resolveInstructions(),
                $this->bootstrapTools()
            ),
        ];
    }

    /**
     * @throws Throwable
     */
    public function chat(Message|array $messages = [], ?InterruptRequest $interrupt = null): Message
    {
        $this->notify('chat-start');

        $messages = \is_array($messages) ? $messages : [$messages];
        $chatHistory = $this->resolveAgentState()->getChatHistory();
        foreach ($messages as $message) {
            $chatHistory->addMessage($message);
        }

        // Prepare workflow nodes for chat mode
        $this->compose([
            new PrepareInferenceNode(
                $this->resolveInstructions(),
                $this->bootstrapTools()
            ),
            new ChatNode($this->resolveProvider()),
        ]);

        // Start workflow execution (Agent IS the workflow)
        $handler = parent::start($interrupt);

        /** @var AgentState $finalState */
        $finalState = $handler->getResult();

        $this->notify('chat-stop');

        return $finalState->getChatHistory()->getLastMessage();
    }

    /**
     * @throws Throwable
     */
    public function stream(Message|array $messages = [], ?InterruptRequest $interrupt = null): \Generator
    {
        $this->notify('stream-start');

        $messages = \is_array($messages) ? $messages : [$messages];
        $chatHistory = $this->resolveAgentState()->getChatHistory();
        foreach ($messages as $message) {
            $chatHistory->addMessage($message);
        }

        // Prepare workflow nodes for streaming mode
        $this->compose([
            new PrepareInferenceNode(
                $this->resolveInstructions(),
                $this->bootstrapTools(),
            ),
            new StreamingNode($this->resolveProvider()),
        ]);

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
     * @throws AgentException
     * @throws Throwable
     */
    public function structured(Message|array $messages = [], ?string $class = null, int $maxRetries = 1, ?InterruptRequest $interrupt = null): mixed
    {
        $this->notify('structured-start');

        $messages = \is_array($messages) ? $messages : [$messages];
        $chatHistory = $this->resolveAgentState()->getChatHistory();
        foreach ($messages as $message) {
            $chatHistory->addMessage($message);
        }

        // Get the output class
        $class ??= $this->getOutputClass();

        // Prepare workflow nodes for structured output mode
        $this->compose([
            new PrepareInferenceNode(
                $this->resolveInstructions(),
                $this->bootstrapTools(),
            ),
            new StructuredOutputNode($this->resolveProvider(), $class, $maxRetries),
        ]);

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
