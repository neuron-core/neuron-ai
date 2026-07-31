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
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Exceptions\AgentException;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\Workflow;
use NeuronAI\Workflow\WorkflowState;
use Throwable;

use function end;
use function is_array;

/**
 * @method static static make(?string $resumeToken = null, ?WorkflowState $state = null)
 * @method AgentStartEvent resolveStartEvent()
 * @method AgentState resolveState()
 */
class Agent extends Workflow implements AgentInterface
{
    use HandleAgentState;
    use ResolveProvider;
    use HandleTools;
    use HandleInstructions;

    protected bool $parallelToolCalls = false;

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
            ? new ParallelToolNode($this->getChatHistory(), $this->toolMaxRuns, $this->resolveToolErrorHandler())
            : new ToolNode($this->getChatHistory(), $this->toolMaxRuns, $this->resolveToolErrorHandler());

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

        // Clone so middleware can modify the event instructions
        // without leaking changes into the agent configuration.
        return new AIInferenceEvent(clone $this->resolveInstructions(), $tools);
    }

    /**
     * @param Message|Message[] $messages
     * @param array<string, mixed>|null $payload Null to start the run; a payload to resume a suspended agent.
     */
    public function chat(
        Message|array $messages = [],
        ?array $payload = null,
        bool $timedOut = false
    ): AgentHandler {
        $this->checkResumeToken($payload);

        $this->resolveStartEvent()->setMessages(
            ...(is_array($messages) ? $messages : [$messages])
        );

        $this->compose(
            new ChatNode($this->resolveProvider(), $this->getChatHistory()),
        );

        return new AgentHandler(
            $this->events($payload, $timedOut),
            $this->getChatHistory(),
        );
    }

    /**
     * @param Message|Message[] $messages
     * @param array<string, mixed>|null $payload Null to start the run; a payload to resume a suspended agent.
     */
    public function stream(
        Message|array $messages = [],
        ?array $payload = null,
        bool $timedOut = false
    ): AgentHandler {
        $this->checkResumeToken($payload);

        $this->resolveStartEvent()->setMessages(
            ...(is_array($messages) ? $messages : [$messages])
        );

        $this->compose(
            new StreamingNode($this->resolveProvider(), $this->getChatHistory()),
        );

        return new AgentHandler(
            $this->events($payload, $timedOut),
            $this->getChatHistory(),
        );
    }

    /**
     * @param Message|Message[] $messages
     * @param array<string, mixed>|null $payload Null to start the run; a payload to resume a suspended agent.
     * @throws AgentException
     * @throws Throwable
     */
    public function structured(
        Message|array $messages = [],
        ?string $class = null,
        int $maxRetries = 1,
        ?array $payload = null,
        bool $timedOut = false
    ): mixed {
        $this->checkResumeToken($payload);

        $this->resolveStartEvent()->setMessages(
            ...(is_array($messages) ? $messages : [$messages])
        );

        $class ??= $this->getOutputClass();

        $this->compose(
            new StructuredOutputNode($this->resolveProvider(), $this->getChatHistory(), $class, $maxRetries),
        );

        /** @var AgentState $finalState */
        $finalState = $payload === null ? $this->run() : $this->resume($payload, $timedOut);

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

    /**
     * A resume payload targets the suspended run recorded on the thread: when the
     * tail of chat history is a ToolCallMessage stamped with a resume token
     * (ADR 0005), that token identifies the workflow to reattach to — chat history
     * is the system of record, so nothing else needs to be stored or passed. With
     * no stamp on the tail the agent keeps its current workflowId (e.g. a
     * resumeToken passed to make() for a non-approval suspension).
     *
     * @param array<string, mixed>|null $payload Null starts a fresh turn; a non-null
     *                                           payload resumes a suspended workflow.
     */
    protected function checkResumeToken(?array $payload): void
    {
        if ($payload === null) {
            return;
        }

        $messages = $this->getChatHistory()->getMessages();
        $last = end($messages);
        $token = $last instanceof ToolCallMessage ? $last->getResumeToken() : null;

        if ($token !== null) {
            $this->workflowId = $token;
        }
    }

}
