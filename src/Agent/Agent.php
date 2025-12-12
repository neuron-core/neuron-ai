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
use NeuronAI\HandleContent;
use NeuronAI\Observability\EventBus;
use NeuronAI\Workflow\Events\Event;
use NeuronAI\Workflow\Interrupt\InterruptRequest;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\Workflow;
use Throwable;

use function is_array;

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
        return 'Your are a helpful and friendly AI agent built with Neuron AI PHP agentic framework.';
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

        $nodes = is_array($nodes) ? $nodes : [$nodes];

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
     */
    public function chat(
        Message|array $messages = [],
        ?InterruptRequest $interrupt = null
    ): AgentHandler {
        // Prepare the workflow for chat mode
        $this->compose(
            new ChatNode($this->resolveProvider(), $messages),
        );

        $handler = parent::init($interrupt);

        return new AgentHandler($handler);
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
        // Prepare the workflow for streaming mode
        $this->compose(
            new StreamingNode($this->resolveProvider(), $messages),
        );

        $handler = parent::init($interrupt);

        return new AgentHandler($handler);
    }

    /**
     * @param Message|Message[] $messages
     * @throws AgentException
     * @throws InspectorException
     * @throws Throwable
     */
    public function structured(
        Message|array $messages = [],
        ?string $class = null,
        int $maxRetries = 1,
        ?InterruptRequest $interrupt = null
    ): mixed {
        EventBus::emit('structured-start', $this);

        $messages = is_array($messages) ? $messages : [$messages];
        foreach ($messages as $message) {
            $this->addToChatHistory($this->resolveState(), $message);
        }

        $class ??= $this->getOutputClass();

        // Prepare workflow nodes for structured output mode
        $this->compose(
            new StructuredOutputNode($this->resolveProvider(), $class, $maxRetries),
        );

        $handler = parent::init($interrupt);

        /** @var AgentState $finalState */
        $finalState = $handler->getResult();

        EventBus::emit('structured-stop', $this);

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
