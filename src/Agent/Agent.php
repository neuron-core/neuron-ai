<?php

declare(strict_types=1);

namespace NeuronAI\Agent;

use Inspector\Exceptions\InspectorException;
use NeuronAI\Agent\Events\AgentStartEvent;
use NeuronAI\Agent\Events\AIInferenceEvent;
use NeuronAI\Agent\Nodes\ChatNode;
use NeuronAI\Agent\Nodes\ParallelToolNode;
use NeuronAI\Agent\Nodes\StreamingNode;
use NeuronAI\Agent\Nodes\StructuredOutputNode;
use NeuronAI\Agent\Nodes\ToolNode;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Exceptions\AgentException;
use NeuronAI\HandleContent;
use NeuronAI\Workflow\Interrupt\InterruptRequest;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\Workflow;
use NeuronAI\Workflow\WorkflowHandlerInterface;
use Throwable;

use function is_array;

/**
 * @method AgentStartEvent resolveStartEvent()
 * @method AgentState resolveState()
 * @method AgentState run()
 */
class Agent extends Workflow implements AgentInterface
{
    use HandleAgentState;
    use ResolveProvider;
    use HandleTools;
    use HandleContent;

    protected string $instructions;

    protected bool $parallelToolCalls = false;

    public function init(?InterruptRequest $resumeRequest = null): WorkflowHandlerInterface
    {
        $this->resolveState()->resetToolRuns();
        $this->resolveState()->resetSteps();

        return parent::init($resumeRequest);
    }

    /**
     * Determines whether tools should be executed in parallel.
     * Override this method to return true to enable parallel tool execution.
     *
     * Note: Parallel execution requires the pcntl extension and spatie/fork package.
     */
    public function parallelToolCalls(bool $enabled): AgentInterface
    {
        $this->parallelToolCalls = $enabled;
        return $this;
    }

    protected function instructions(): string
    {
        return (string) new SystemPrompt(
            background: ['Your are a helpful and friendly AI agent built with Neuron AI - the first agentic framework for the PHP ecosystem.']
        );
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
     * Prepare the agent workflow with mode-specific nodes.
     * Since Agent extends Workflow, we configure the current instance.
     *
     * @param Node|Node[] $nodes Mode-specific nodes (ChatNode, StreamingNode, etc.)
     */
    protected function compose(array|Node $nodes): void
    {
        if ($this->eventNodeMap !== []) {
            // it's already been bootstrapped
            return;
        }

        $nodes = is_array($nodes) ? $nodes : [$nodes];

        // Select the appropriate ToolNode based on the parallel execution setting
        $toolNode = $this->parallelToolCalls
            ? new ParallelToolNode($this->toolMaxRuns, $this->resolveToolErrorHandler())
            : new ToolNode($this->toolMaxRuns, $this->resolveToolErrorHandler());

        // Add nodes to the workflow instance
        $this->addNodes([
            ...$nodes,
            $toolNode,
        ]);
    }

    /**
     * @throws InspectorException
     */
    protected function startEvent(): AgentStartEvent
    {
        // The bootstrapTools method modifies the instructions, adding the toolkit guidelines, so we need to resolve them first
        $tools = $this->bootstrapTools();
        $instructions = $this->resolveInstructions();

        return new AIInferenceEvent($instructions, $tools);
    }

    /**
     * @param Message|Message[] $messages
     */
    public function chat(
        Message|array $messages = [],
        ?InterruptRequest $interrupt = null
    ): AgentHandler {
        $this->resolveStartEvent()->setMessages(
            ...(is_array($messages) ? $messages : [$messages])
        );

        // Prepare the workflow for chat mode
        $this->compose(
            new ChatNode($this->resolveProvider()),
        );

        return new AgentHandler($this, $interrupt);
    }

    /**
     * Stream agent response
     *
     * @param Message|Message[] $messages
     */
    public function stream(
        Message|array $messages = [],
        ?InterruptRequest $interrupt = null
    ): AgentHandler {
        $this->resolveStartEvent()->setMessages(
            ...(is_array($messages) ? $messages : [$messages])
        );

        // Prepare the workflow for streaming mode
        $this->compose(
            new StreamingNode($this->resolveProvider()),
        );

        return new AgentHandler($this, $interrupt);
    }

    /**
     * @param Message|Message[] $messages
     * @throws AgentException
     * @throws Throwable
     */
    public function structured(
        Message|array $messages = [],
        ?string $class = null,
        int $maxRetries = 1,
        ?InterruptRequest $interrupt = null
    ): mixed {
        $this->resolveStartEvent()->setMessages(
            ...(is_array($messages) ? $messages : [$messages])
        );

        $class ??= $this->getOutputClass();

        // Prepare workflow nodes for structured output mode
        $this->compose(
            new StructuredOutputNode($this->resolveProvider(), $class, $maxRetries),
        );

        $handler = parent::init($interrupt);

        /** @var AgentState $finalState */
        $finalState = $handler->run();

        // Return the structured output object
        return $finalState->get('structured_output');
    }

    /**
     * Get the class representing the structured output.
     * Override this method in subclasses to provide a default output class.
     *
     * @throws AgentException
     */
    protected function getOutputClass(): string
    {
        throw new AgentException('You need to specify a structured output class.');
    }
}
