<?php

declare(strict_types=1);

namespace NeuronAI\Agent;

use NeuronAI\Agent\Events\AgentStartEvent;
use NeuronAI\Agent\Events\AIInferenceEvent;
use NeuronAI\Agent\Events\ToolCallEvent;
use NeuronAI\Agent\Nodes\ChatNode;
use NeuronAI\Agent\Nodes\ParallelToolNode;
use NeuronAI\Agent\Nodes\StreamingNode;
use NeuronAI\Agent\Nodes\StructuredOutputNode;
use NeuronAI\Agent\Nodes\ToolNode;
use NeuronAI\Chat\History\ChatHistoryInterface;
use NeuronAI\Chat\History\InMemoryChatHistory;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Exceptions\AgentException;
use NeuronAI\Exceptions\WorkflowException;
use NeuronAI\Workflow\Events\Event;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\Workflow;
use NeuronAI\Workflow\WorkflowInterface;
use NeuronAI\Workflow\WorkflowState;
use Throwable;

use function end;
use function is_array;

/**
 * @method static static make(?string $runId = null, ?WorkflowState $state = null)
 * @method AgentStartEvent resolveStartEvent()
 * @method AgentState resolveState()
 */
class Agent extends Workflow implements AgentInterface
{
    use ResolveProvider;
    use HandleTools;
    use HandleInstructions;

    protected ChatHistoryInterface $chatHistory;

    protected bool $parallelToolCalls = false;

    /**
     * Determines whether tools should be executed in parallel.
     */
    public function parallelToolCalls(bool $enabled): AgentInterface
    {
        $this->parallelToolCalls = $enabled;
        return $this;
    }

    protected function state(): AgentState
    {
        return new AgentState();
    }

    protected function chatHistory(): ChatHistoryInterface
    {
        return new InMemoryChatHistory();
    }

    public function setChatHistory(ChatHistoryInterface $chatHistory): self
    {
        $this->chatHistory = $chatHistory;
        return $this;
    }

    public function getChatHistory(): ChatHistoryInterface
    {
        return $this->chatHistory ??= $this->chatHistory();
    }

    /**
     * Steps are per-execution-cycle: a state restored from a replayed snapshot
     * starts a fresh transcript. Serializing backends already strip it
     * (AgentState::__serialize); this covers InMemoryPersistence, which stores
     * live object references.
     */
    public function setState(WorkflowState $state): WorkflowInterface
    {
        if ($state instanceof AgentState && $state !== $this->state && $state->getSteps() !== []) {
            $state = clone $state;
            $state->resetSteps();
        }

        return parent::setState($state);
    }

    /**
     * The agent's transient capability is its live tool registry: persisted
     * events drop it (AIInferenceEvent::__serialize — tools hold connections,
     * clients, closures), so a recalled inference or tool-call event comes back
     * with an empty tool list. Re-seed the base registry here; middleware
     * re-supply their own additions in before() (each contributor restores what
     * it contributes). Idempotent: a live tool-calling event never has an empty
     * list, so this only ever touches stripped events.
     */
    public function restoreEventNode(Event $event): Event
    {
        $inference = match (true) {
            $event instanceof AIInferenceEvent => $event,
            $event instanceof ToolCallEvent => $event->inferenceEvent,
            default => null,
        };

        if ($inference instanceof AIInferenceEvent && $inference->tools === []) {
            $inference->tools = $this->bootstrapTools();
        }

        return $event;
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
     * @throws WorkflowException
     */
    public function chat(
        Message|array $messages = [],
        ?array $payload = null
    ): AgentHandler {
        $this->checkRunId($payload);

        $this->resolveStartEvent()->setMessages(
            ...(is_array($messages) ? $messages : [$messages])
        );

        $this->compose(
            new ChatNode($this->resolveProvider(), $this->getChatHistory()),
        );

        return new AgentHandler(
            $this->events($payload),
            $this->getChatHistory(),
        );
    }

    /**
     * @param Message|Message[] $messages
     * @param array<string, mixed>|null $payload Null to start the run; a payload to resume a suspended agent.
     * @throws WorkflowException
     */
    public function stream(
        Message|array $messages = [],
        ?array $payload = null,
    ): AgentHandler {
        $this->checkRunId($payload);

        $this->resolveStartEvent()->setMessages(
            ...(is_array($messages) ? $messages : [$messages])
        );

        $this->compose(
            new StreamingNode($this->resolveProvider(), $this->getChatHistory()),
        );

        return new AgentHandler(
            $this->events($payload),
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
    ): mixed {
        $this->checkRunId($payload);

        $this->resolveStartEvent()->setMessages(
            ...(is_array($messages) ? $messages : [$messages])
        );

        $class ??= $this->getOutputClass();

        $this->compose(
            new StructuredOutputNode($this->resolveProvider(), $this->getChatHistory(), $class, $maxRetries),
        );

        /** @var AgentState $finalState */
        $finalState = $payload === null ? $this->run() : $this->resume($payload);

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
     * tail of chat history is a ToolCallMessage stamped with a runId (ADR 0005),
     * that id identifies the run to reattach to — chat history is the system of
     * record, so nothing else needs to be stored or passed. With no stamp on the
     * tail the agent keeps its current runId (e.g. a runId passed to make() for a
     * non-approval suspension).
     *
     * @param array<string, mixed>|null $payload Null starts a fresh turn; a non-null
     *                                           payload resumes a suspended workflow.
     */
    protected function checkRunId(?array $payload): void
    {
        if ($payload === null) {
            return;
        }

        $messages = $this->getChatHistory()->getMessages();
        $last = end($messages);
        $runId = $last instanceof ToolCallMessage ? $last->getRunId() : null;

        if ($runId !== null) {
            $this->runId = $runId;
        }
    }

}
