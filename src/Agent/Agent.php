<?php

declare(strict_types=1);

namespace NeuronAI\Agent;

use Closure;
use Generator;
use NeuronAI\Agent\Events\AgentStartEvent;
use NeuronAI\Agent\Interrupt\ApprovalRequest;
use NeuronAI\Agent\Interrupt\ApprovalTranslator;
use NeuronAI\Agent\Interrupt\ToolResultsTranslator;
use NeuronAI\Agent\Nodes\AwaitToolResultsNode;
use NeuronAI\Agent\Nodes\ChatNode;
use NeuronAI\Agent\Nodes\AgentEndNode;
use NeuronAI\Agent\Nodes\ParallelToolNode;
use NeuronAI\Agent\Nodes\AgentStartNode;
use NeuronAI\Agent\Nodes\StructuredOutputNode;
use NeuronAI\Agent\Nodes\ToolNode;
use NeuronAI\Chat\History\ChatHistoryInterface;
use NeuronAI\Chat\History\InMemoryChatHistory;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Exceptions\AgentException;
use NeuronAI\Exceptions\ChatHistoryException;
use NeuronAI\Exceptions\InputTranslationException;
use NeuronAI\Exceptions\WorkflowException;
use NeuronAI\Workflow\Interrupt\Action;
use NeuronAI\Workflow\Executor\ExecutionRequest;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\PendingExecution;
use NeuronAI\Workflow\Streaming\Adapter\StreamAdapterInterface;
use NeuronAI\Workflow\Workflow;
use NeuronAI\Workflow\ExecutionContext;
use NeuronAI\Workflow\WorkflowExecution;
use NeuronAI\Workflow\Events\Event;
use NeuronAI\Workflow\WorkflowState;
use Throwable;

use function array_filter;
use function array_values;
use function end;
use function is_array;
use function hash;

/**
 * @extends Workflow<AgentState>
 * @method static static make(?string $workflowId = null, ?AgentState $state = null, ?string $threadId = null)
 * @method AgentStartEvent getStartEvent()
 * @method static setStreamAdapter(?StreamAdapterInterface $adapter) Configure Workflow-owned stream adaptation.
 */
class Agent extends Workflow implements AgentInterface
{
    use HandleProvider;
    use HandleTools;
    use HandleInstructions;

    protected ?ChatHistoryInterface $chatHistory = null;
    protected InMemoryChatHistory $defaultHistory;

    /** Configured conversation address, independent of run identity. */
    protected ?string $threadId = null;

    protected bool $parallelToolCalls = false;

    protected ?Closure $beforeParallelToolChild = null;

    protected ?Closure $afterParallelToolChild = null;

    /**
     * @throws WorkflowException
     * @throws AgentException
     */
    public function __construct(?string $workflowId = null, ?AgentState $state = null, ?string $threadId = null)
    {
        if ($workflowId !== null && $threadId !== null && $workflowId !== $threadId) {
            throw new AgentException('Conflicting workflow and thread identity.');
        }
        $this->defaultHistory = new InMemoryChatHistory($threadId ?? $workflowId);
        $this->threadId = $threadId ?? $workflowId ?? $this->defaultHistory->getThreadId();
        parent::__construct($workflowId, $state);
    }

    /**
     * Determines whether tools should be executed in parallel and optionally
     * configures callbacks to initialize and clean up resources in each child process.
     *
     * Note: Parallel execution requires the pcntl extension and spatie/fork package.
     */
    public function parallelToolCalls(
        bool $enabled = true,
        ?callable $beforeChild = null,
        ?callable $afterChild = null,
    ): AgentInterface {
        $this->parallelToolCalls = $enabled;
        $this->beforeParallelToolChild = $beforeChild !== null
            ? $beforeChild(...)
            : null;
        $this->afterParallelToolChild = $afterChild !== null
            ? $afterChild(...)
            : null;

        return $this;
    }

    protected function state(): AgentState
    {
        return new AgentState();
    }

    /**
     * Ten minutes: above any single provider or tool call in ordinary use,
     * and the longest a thread stays refused after its process was killed
     * with no chance to record the failure. Override the hook, or call
     * setLeaseTimeout() (null disables), for slower nodes.
     */
    protected function leaseTimeout(): ?int
    {
        return 600;
    }

    /**
     * @throws ChatHistoryException
     */
    protected function chatHistory(string $threadId): ChatHistoryInterface
    {
        return $this->defaultHistory;
    }

    /**
     * A pre-bound history explicitly selects the conversation; an unbound
     * one receives the current thread identity. Swapping conversations changes
     * configuration while preserving previous results and durable runs.
     *
     * @throws AgentException
     */
    public function setChatHistory(ChatHistoryInterface $chatHistory): self
    {
        $this->threadId = $chatHistory->getThreadId() ?? $this->threadId;
        $this->workflowId = null;
        $chatHistory->setThreadId($this->threadId);
        $this->chatHistory = $chatHistory;
        return $this;
    }

    /**
     * History factories must agree with the configured conversation. Only
     * an explicit setChatHistory() call may select a different conversation.
     *
     * @throws AgentException
     */

    public function getChatHistory(): ChatHistoryInterface
    {
        $history = $this->chatHistory ?? $this->chatHistory($this->getWorkflowId());
        $threadId = $history->getThreadId();
        if ($threadId !== null && $threadId !== $this->getWorkflowId()) {
            throw new AgentException('Chat history conflicts with the configured conversation.');
        }
        if ($threadId === null) {
            $history->setThreadId($this->getWorkflowId());
        }
        return $history;
    }

    protected function execution(ExecutionContext $context): WorkflowExecution
    {
        return new AgentExecution(
            $context,
            $this,
            $this->newState(),
            $this->executionMiddleware(),
            $this->executionGlobalMiddleware(),
            $this->getProvider($context),
            $this->getChatHistory(),
            $this->getInstructions($context),
            $this->getTools($context),
        );
    }

    /**
     * Clear chat history and abandon the pending execution for this conversation.
     */
    public function resetConversation(): self
    {
        $chatHistory = $this->getChatHistory();

        // The history is wiped below, so a pending approval cannot dangle:
        // the engine verb frees the thread without abandonRun()'s guard.
        parent::abandonRun();

        $chatHistory->flushAll();

        return $this;
    }

    /**
     * Suspended tool cycles already have an assistant tool call in history.
     * They must be settled before abandonment to avoid leaving an unanswered
     * call in the next inference's context.
     *
     * @throws AgentException
     */
    public function abandonRun(?string $expectedRunId = null, ?int $expectedExecutionAttempt = null, ?string $workflowId = null): bool
    {
        $messages = $this->getChatHistory()->getMessages();
        $lastMessage = end($messages);
        if ($lastMessage instanceof ToolCallMessage) {
            throw new AgentException(
                'The conversation has an unanswered tool call: settle its approval or result '
                . 'with submitInputs() before abandoning the run.'
            );
        }

        return parent::abandonRun($expectedRunId, $expectedExecutionAttempt, $workflowId);
    }

    /**
     * @param AgentExecution $execution
     * @return Node[]
     */
    protected function nodes(WorkflowExecution $execution): array
    {

        $chatHistory = $execution->getChatHistory();

        $toolNode = $this->parallelToolCalls
            ? new ParallelToolNode(
                $chatHistory,
                $this->toolMaxRuns,
                $this->resolveToolErrorHandler(),
                $this->beforeParallelToolChild,
                $this->afterParallelToolChild,
            )
            : new ToolNode($chatHistory, $this->toolMaxRuns, $this->resolveToolErrorHandler());

        $nodes = [
            ...$this->entryNodes($execution),
            new ChatNode($execution->getProvider(), $chatHistory),
            new StructuredOutputNode($execution->getProvider(), $chatHistory),
            $toolNode,
            new AwaitToolResultsNode($chatHistory),
        ];

        return [...$nodes, ...$this->exitNodes($execution)];
    }

    /**
     * @param AgentExecution $execution
     * @return Node[]
     */
    protected function exitNodes(WorkflowExecution $execution): array
    {
        return [new AgentEndNode()];
    }

    /**
     * Hook method for child classes.
     *
     * @param AgentExecution $execution
     * @return Node[]
     */
    protected function entryNodes(WorkflowExecution $execution): array
    {
        $tools = $execution->getTools();

        return [
            new AgentStartNode(
                $execution->getInstructions(),
                $tools,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function ignitionContext(): array
    {
        $threadId = $this->getThreadId();

        return $threadId === null ? [] : ['threadId' => $threadId];
    }

    /** @param array<string, mixed> $context */
    protected function ignitionFingerprint(Event $event, array $context): string
    {
        if (!$event instanceof AgentStartEvent) {
            return parent::ignitionFingerprint($event, $context);
        }
        $event = clone $event;
        foreach ($event->messages as $index => $message) {
            $event->messages[$index] = clone $message;
            // Message construction assigns a fresh display ID on every retry.
            $event->messages[$index]->addMetadata('__id', null);
        }
        return hash('sha256', $this->getSerializer()->serialize([$event, $context]));
    }

    /** The configured conversation address; no active run is needed. */
    public function getThreadId(): ?string
    {
        return $this->threadId;
    }

    /**
     * The Agent's business identity is the conversation: the threadId IS the
     * workflow ID, so a continuation holding only the thread finds the run.
     */
    public function workflowId(): ?string
    {
        return $this->getThreadId();
    }

    protected function startEvent(): AgentStartEvent
    {
        return new AgentStartEvent();
    }

    /**
     * A new turn starts a new run — to continue a suspended run use
     * {@see run()}. Runs eagerly to completion; the returned state
     * surfaces an approval pause via {@see WorkflowState::isInterrupted()}.
     *
     * @param Message|Message[] $messages
     * @throws Throwable
     * @throws WorkflowException
     */
    public function chat(Message|array $messages = [], ?string $idempotencyKey = null): AgentState
    {
        $event = $this->startEvent();
        $event->messages = is_array($messages) ? $messages : [$messages];

        return $this->run(ExecutionRequest::start($event, idempotencyKey: $idempotencyKey));
    }

    /**
     * Return a lazy generator of native chunks or adapted protocol events.
     * Iteration also delivers to a configured channel;
     * {@see Generator::getReturn()} is the final {@see AgentState}.
     *
     * @param Message|Message[] $messages
     * @return Generator<int, object, mixed, AgentState>
     * @throws Throwable
     * @throws WorkflowException
     */
    public function stream(Message|array $messages = [], ?string $idempotencyKey = null): Generator
    {
        $event = $this->startEvent();
        $event->options->stream = true;
        $event->messages = is_array($messages) ? $messages : [$messages];
        return $this->events(ExecutionRequest::start($event, idempotencyKey: $idempotencyKey));
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
        ?string $idempotencyKey = null,
    ): mixed {
        $event = $this->startEvent();
        $event->options->outputClass = $class ?? $this->getOutputClass();
        $event->options->maxRetries = $maxRetries;
        $event->messages = is_array($messages) ? $messages : [$messages];

        $finalState = $this->run(ExecutionRequest::start($event, idempotencyKey: $idempotencyKey));

        return $finalState->get('structured_output');
    }

    /**
     * @throws AgentException
     */
    protected function getOutputClass(): string
    {
        throw new AgentException('You need to set a structured output class.');
    }

    /**
     * The tool calls still awaiting a human decision on the current interruption.
     *
     * @return Action[]
     */
    public function pendingApprovals(): array
    {
        $request = $this->inspect()?->interrupt;

        if (!$request instanceof ApprovalRequest) {
            return [];
        }

        return array_values(array_filter(
            $request->getActions(),
            static fn (Action $action): bool => $action->isPending(),
        ));
    }

    /**
     * @return PendingExecution<AgentState>
     * @throws InputTranslationException
     * @throws WorkflowException
     */
    public function submitApprovalDecisions(array $decisions, ?string $idempotencyKey = null): PendingExecution
    {
        return $this->submitInputs($decisions, new ApprovalTranslator(), $idempotencyKey);
    }

    /**
     * @return PendingExecution<AgentState>
     * @throws InputTranslationException
     * @throws WorkflowException
     */
    public function submitToolResults(array $results, ?string $idempotencyKey = null): PendingExecution
    {
        return $this->submitInputs($results, new ToolResultsTranslator(), $idempotencyKey);
    }
}
