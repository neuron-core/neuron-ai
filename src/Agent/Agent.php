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
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Exceptions\AgentException;
use NeuronAI\Exceptions\WorkflowException;
use NeuronAI\Observability\Observable;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\StaticConstructor;
use NeuronAI\Workflow\Event;
use NeuronAI\Workflow\Interrupt\InterruptRequest;
use NeuronAI\Workflow\Middleware\WorkflowMiddleware;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\NodeInterface;
use NeuronAI\Workflow\Persistence\InMemoryPersistence;
use NeuronAI\Workflow\Persistence\PersistenceInterface;
use NeuronAI\Workflow\Workflow;

/**
 * Agent implementation built on top of the Workflow system.
 *
 * This implementation leverages workflow features like:
 * - Interruption for human-in-the-loop patterns
 * - Persistence for resuming workflows
 * - Checkpoints for state management
 * - Event-driven architecture for complex execution flows
 */
class Agent implements AgentInterface
{
    use StaticConstructor;
    use Observable;
    use ResolveState;
    use ResolveProvider;
    use HandleTools;

    protected AIProviderInterface $provider;

    protected string $instructions = '';

    protected bool $parallelToolCalls = false;

    /**
     * Middleware to be registered with the workflow.
     *
     * @var array<class-string<NodeInterface>, WorkflowMiddleware|WorkflowMiddleware[]>
     */
    protected array $agentMiddleware = [];

    public function __construct(
        protected PersistenceInterface $persistence = new InMemoryPersistence(),
        protected ?string $workflowId = null
    ) {
        $this->workflowId ??= \uniqid('neuron_agent_');
    }

    public function instructions(): string
    {
        return 'You are a helpful and friendly AI agent built with Neuron PHP framework.';
    }

    public function setInstructions(string $instructions): self
    {
        $this->instructions = $instructions;
        return $this;
    }

    public function resolveInstructions(): string
    {
        return $this->instructions !== '' ? $this->instructions : $this->instructions();
    }

    public function setChatHistory(ChatHistoryInterface $chatHistory): self
    {
        $this->resolveAgentState()->setChatHistory($chatHistory);
        return $this;
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
     * Register middleware for a specific node class.
     *
     * @param class-string<NodeInterface>|array<class-string<NodeInterface>> $nodeClass Node class name or array of node class names
     * @param WorkflowMiddleware|WorkflowMiddleware[] $middleware Middleware instance(s) (required when $nodeClass is a string)
     * @throws WorkflowException
     */
    public function middleware(string|array $nodeClass, WorkflowMiddleware|array $middleware): self
    {
        $nodeClasses = \is_array($nodeClass) ? $nodeClass : [$nodeClass];
        $middlewareArray = \is_array($middleware) ? $middleware : [$middleware];

        foreach ($nodeClasses as $class) {
            if (!isset($this->agentMiddleware[$class])) {
                $this->agentMiddleware[$class] = [];
            }

            foreach ($middlewareArray as $m) {
                if (! $m instanceof WorkflowMiddleware) {
                    throw new WorkflowException('Middleware must be an instance of WorkflowMiddleware');
                }
                $this->agentMiddleware[$class][] = $m;
            }
        }

        return $this;
    }

    protected function removeDelimitedContent(string $text, string $openTag, string $closeTag): string
    {
        $escapedOpenTag = \preg_quote($openTag, '/');
        $escapedCloseTag = \preg_quote($closeTag, '/');
        $pattern = '/' . $escapedOpenTag . '.*?' . $escapedCloseTag . '/s';
        return \preg_replace($pattern, '', $text);
    }

    /**
     * Build the workflow with nodes.
     *
     * @param Node|Node[] $nodes
     * @throws WorkflowException
     */
    protected function buildWorkflow(array|Node $nodes): Workflow
    {
        $nodes = \is_array($nodes) ? $nodes : [$nodes];

        // Select the appropriate ToolNode based on the parallel execution setting
        $toolNode = $this->parallelToolCalls()
            ? new ParallelToolNode($this->toolMaxTries)
            : new ToolNode($this->toolMaxTries);

        $workflow = Workflow::make($this->resolveAgentState(), $this->persistence, $this->workflowId)
            ->addNodes([
                ...$nodes,
                new RouterNode(),
                $toolNode,
            ]);

        // Register pending middleware with the workflow
        foreach ($this->agentMiddleware as $node => $middleware) {
            $workflow->middleware($node, $middleware);
        }

        // Share observers with the workflow
        foreach ($this->observers as $event => $observers) {
            foreach ($observers as $observer) {
                $workflow->observe($observer, $event);
            }
        }

        return $workflow;
    }

    /**
     * Execute the chat.
     *
     * @param Message|Message[] $messages Messages to send (optional when resuming)
     * @param InterruptRequest|null $interrupt If provided, resumes from interruption
     * @throws \Throwable
     */
    public function chat(Message|array $messages = [], ?InterruptRequest $interrupt = null): Message
    {
        $this->notify('chat-start');

        $messages = \is_array($messages) ? $messages : [$messages];
        $chatHistory = $this->resolveAgentState()->getChatHistory();
        foreach ($messages as $message) {
            $chatHistory->addMessage($message);
        }

        $workflow = $this->buildWorkflow([
            new PrepareInferenceNode(
                $this->resolveInstructions(),
                $this->bootstrapTools()
            ),
            new ChatNode($this->resolveProvider()),
        ]);
        $handler = $workflow->start($interrupt);

        /** @var AgentState $finalState */
        $finalState = $handler->getResult();

        $this->notify('chat-stop');

        return $finalState->getChatHistory()->getLastMessage();
    }

    /**
     * Execute the chat with streaming.
     *
     * @param Message|Message[] $messages Messages to send (optional when resuming)
     * @param InterruptRequest|null $interrupt If provided, resumes from interruption
     * @throws \Throwable
     */
    public function stream(Message|array $messages = [], ?InterruptRequest $interrupt = null): \Generator
    {
        $this->notify('stream-start');

        $messages = \is_array($messages) ? $messages : [$messages];
        $chatHistory = $this->resolveAgentState()->getChatHistory();
        foreach ($messages as $message) {
            $chatHistory->addMessage($message);
        }

        $workflow = $this->buildWorkflow([
            new PrepareInferenceNode(
                $this->resolveInstructions(),
                $this->bootstrapTools()
            ),
            new StreamingNode($this->resolveProvider()),
        ]);
        $handler = $workflow->start($interrupt);

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
     * Execute structured output extraction.
     *
     * @param Message|Message[] $messages Messages to send (optional when resuming)
     * @param string|null $class Output class name
     * @param int $maxRetries Maximum number of retries for validation errors
     * @param InterruptRequest|null $interrupt If provided, resumes from interruption
     * @throws AgentException
     * @throws \Throwable
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

        $workflow = $this->buildWorkflow([
            new PrepareInferenceNode(
                $this->resolveInstructions(),
                $this->bootstrapTools(),
                $class,
                $maxRetries
            ),
            new StructuredOutputNode($this->resolveProvider()),
        ]);
        $handler = $workflow->start($interrupt);

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
