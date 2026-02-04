<?php

declare(strict_types=1);

namespace NeuronAI\Agent;

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
use NeuronAI\Observability\EventBus;
use NeuronAI\Workflow\Interrupt\InterruptRequest;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\Workflow;
use Throwable;

use function is_array;

/**
 * @method AgentStartEvent resolveStartEvent()
 */
class Agent extends Workflow implements AgentInterface
{
    use HandleAgentState;
    use ResolveProvider;
    use HandleTools;
    use HandleContent;

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
            // it's already been bootstrapped
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

    protected function startEvent(): AgentStartEvent
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
        $this->resolveStartEvent()->setMessages($messages);

        // Prepare the workflow for chat mode
        $this->compose(
            new ChatNode($this->resolveProvider()),
        );

        return new AgentHandler(parent::init($interrupt));
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
        $this->resolveStartEvent()->setMessages($messages);

        // Prepare the workflow for streaming mode
        $this->compose(
            new StreamingNode($this->resolveProvider()),
        );

        return new AgentHandler(parent::init($interrupt));
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
        EventBus::emit('structured-start', $this, null, $this->workflowId);

        $this->resolveStartEvent()->setMessages($messages);

        $class ??= $this->getOutputClass();

        // Prepare workflow nodes for structured output mode
        $this->compose(
            new StructuredOutputNode($this->resolveProvider(), $class, $maxRetries),
        );

        $handler = parent::init($interrupt);

        /** @var AgentState $finalState */
        $finalState = $handler->run();

        EventBus::emit('structured-stop', $this, null, $this->workflowId);

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
