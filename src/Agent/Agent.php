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
use NeuronAI\Workflow\Executor\WorkflowExecutorInterface;
use NeuronAI\Workflow\Interrupt\InterruptRequest;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\Workflow;
use Throwable;

use function is_array;

/**
 * @method AgentStartEvent resolveStartEvent()
 * @method AgentState resolveState()
 */
class Agent extends Workflow implements AgentInterface
{
    use HandleAgentState;
    use ResolveProvider;
    use HandleTools;
    use HandleContent;
    use HandleInstructions;

    protected bool $parallelToolCalls = false;

    public function __construct(
        ?WorkflowExecutorInterface $executor = null,
        ?AgentState                $state = null,
        ?string                    $resumeToken = null,
    ) {
        parent::__construct($resumeToken, $state);

        if ($executor instanceof WorkflowExecutorInterface) {
            $this->setExecutor($executor);
        }
    }

    /**
     * Determines whether tools should be executed in parallel.
     */
    public function parallelToolCalls(bool $enabled): AgentInterface
    {
        $this->parallelToolCalls = $enabled;
        return $this;
    }

    /**
     * Prepare the agent workflow with mode-specific nodes.
     *
     * @param Node|Node[] $nodes Mode-specific nodes (ChatNode, StreamingNode, etc.)
     */
    protected function compose(array|Node $nodes): void
    {
        if ($this->eventNodeMap !== []) {
            return;
        }

        $nodes = is_array($nodes) ? $nodes : [$nodes];

        $toolNode = $this->parallelToolCalls
            ? new ParallelToolNode($this->toolMaxRuns, $this->resolveToolErrorHandler())
            : new ToolNode($this->toolMaxRuns, $this->resolveToolErrorHandler());

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

        $this->compose(
            new ChatNode($this->resolveProvider()),
        );

        return new AgentHandler(
            $this->events($interrupt)
        );
    }

    /**
     * @param Message|Message[] $messages
     */
    public function stream(
        Message|array $messages = [],
        ?InterruptRequest $interrupt = null
    ): AgentHandler {
        $this->resolveStartEvent()->setMessages(
            ...(is_array($messages) ? $messages : [$messages])
        );

        $this->compose(
            new StreamingNode($this->resolveProvider()),
        );

        return new AgentHandler(
            $this->events($interrupt)
        );
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

        $this->compose(
            new StructuredOutputNode($this->resolveProvider(), $class, $maxRetries),
        );

        /** @var AgentState $finalState */
        $finalState = $this->run($interrupt);

        return $finalState->get('structured_output');
    }

    /**
     * Get the class representing the structured output.
     *
     * @throws AgentException
     */
    protected function getOutputClass(): string
    {
        throw new AgentException('You need to set a structured output class.');
    }
}
