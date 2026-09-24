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
use NeuronAI\Chat\History\ChatHistory;
use NeuronAI\Chat\History\InMemoryMessageStore;
use NeuronAI\Chat\History\MessageStoreInterface;
use NeuronAI\Chat\Messages\ContentBlocks\SystemContent;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\SystemMessage;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Exceptions\AgentException;
use NeuronAI\Exceptions\InputTranslationException;
use NeuronAI\Exceptions\WorkflowException;
use NeuronAI\Agent\Interrupt\Action;
use NeuronAI\Tools\ProviderToolInterface;
use NeuronAI\Tools\ToolInterface;
use NeuronAI\Tools\ToolRegistry;
use NeuronAI\Tools\Toolkits\ToolkitInterface;
use NeuronAI\Workflow\Executor\ExecutionRequest;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\PendingExecution;
use NeuronAI\Workflow\Workflow;
use NeuronAI\Workflow\WorkflowState;
use NeuronAI\Workflow\WorkflowStatus;
use ReflectionClass;
use Throwable;

use function array_filter;
use function array_map;
use function array_merge;
use function array_values;
use function end;
use function implode;
use function in_array;
use function is_array;
use function serialize;
use function unserialize;

use const PHP_EOL;

/**
 * @extends Workflow<AgentState>
 * @method static static make(?string $workflowId = null, ?AgentState $state = null)
 * @method AgentStartEvent getStartEvent()
 */
class Agent extends Workflow implements AgentInterface
{
    use HandleProvider;
    use HandleTools;
    use HandleInstructions;

    protected ?MessageStoreInterface $messageStore = null;

    protected ?int $contextWindow = null;

    protected bool $parallelToolCalls = false;

    protected ?Closure $beforeParallelToolChild = null;

    protected ?Closure $afterParallelToolChild = null;

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
     * The default keeps conversations in process memory, for as long as this Agent lives.
     */
    protected function messageStore(): MessageStoreInterface
    {
        return new InMemoryMessageStore();
    }

    public function setMessageStore(MessageStoreInterface $store): self
    {
        $this->messageStore = $store;
        return $this;
    }

    protected function resolveMessageStore(): MessageStoreInterface
    {
        return $this->messageStore ??= $this->messageStore();
    }

    /**
     * The token budget of the conversation sent to the model: size it to the
     * provider's model. An explicit setContextWindow() wins over this hook.
     */
    protected function contextWindow(): int
    {
        return ChatHistory::DEFAULT_CONTEXT_WINDOW;
    }

    public function setContextWindow(int $tokens): self
    {
        $this->contextWindow = $tokens;
        return $this;
    }

    /**
     * A fresh view of the conversation on every call: every execution segment
     * opens its own. Nodes and middleware read the history of the node they wrap;
     * writing through this view while an execution is running is unsupported.
     */
    final public function getChatHistory(): ChatHistory
    {
        return new ChatHistory(
            $this->resolveMessageStore(),
            $this->getThreadId() ?? throw new AgentException(
                'Chat history requires a conversation identity: call setThreadId() or execute the Agent first.'
            ),
            $this->contextWindow ?? $this->contextWindow()
        );
    }

    /**
     * The provider, the conversation, the instructions and the tools, built
     * fresh for every execution segment. Toolkits are flattened into their
     * tools and their guidelines join the instructions.
     */
    protected function resources(): AgentResources
    {
        [$instructions, $tools] = $this->resolveTools();

        return new AgentResources($this->getProvider(), $this->getChatHistory(), $instructions, new ToolRegistry($tools));
    }

    /**
     * @return array{SystemMessage, array<ToolInterface|ProviderToolInterface>}
     */
    protected function resolveTools(): array
    {
        $tools = [];
        $guidelines = [];

        foreach ($this->getTools() as $tool) {
            if ($tool instanceof ToolkitInterface) {
                $kitGuidelines = $tool->guidelines();
                if ($kitGuidelines !== null && $kitGuidelines !== '') {
                    $name = (new ReflectionClass($tool))->getShortName();
                    $kitGuidelines = '# '.$name.PHP_EOL.$kitGuidelines;
                }
                $innerTools = $tool->tools();
                $tools = array_merge($tools, $innerTools);

                if (!in_array($kitGuidelines, [null, '', '0'], true)) {
                    $kitGuidelines .= PHP_EOL.implode(
                        PHP_EOL.'- ',
                        array_map(
                            fn (ToolInterface $tool): string => $tool->getName(),
                            $innerTools
                        )
                    );

                    $guidelines[] = $kitGuidelines;
                }
            } elseif ($tool->isVisible()) {
                $tools[] = $tool;
            }
        }

        // A copy: the guidelines must not reach the configured instructions.
        $blocks = unserialize(serialize($this->getInstructions()))->getContentBlocks();

        if ($guidelines !== []) {
            $blocks[] = new SystemContent(
                '<TOOLS-GUIDELINES>'.PHP_EOL.implode(PHP_EOL.PHP_EOL, $guidelines).PHP_EOL.'</TOOLS-GUIDELINES>'
            );
        }

        return [new SystemMessage($blocks), $tools];
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
    public function abandonRun(?string $expectedRunId = null, ?int $expectedExecutionAttempt = null): bool
    {
        $messages = $this->getChatHistory()->getMessages();
        $lastMessage = end($messages);
        if ($lastMessage instanceof ToolCallMessage) {
            throw new AgentException(
                'The conversation has an unanswered tool call: settle its approval or result '
                . 'with submitInputs() before abandoning the run.'
            );
        }

        return parent::abandonRun($expectedRunId, $expectedExecutionAttempt);
    }

    /**
     * @return Node[]
     */
    protected function nodes(): array
    {
        $toolErrorHandler = $this->toolErrorHandler ?? $this->resolveToolErrorHandler();

        $toolNode = $this->parallelToolCalls
            ? new ParallelToolNode(
                $this->toolMaxRuns,
                $toolErrorHandler,
                $this->beforeParallelToolChild,
                $this->afterParallelToolChild,
            )
            : new ToolNode($this->toolMaxRuns, $toolErrorHandler);

        $nodes = [
            ...$this->entryNodes(),
            new ChatNode(),
            new StructuredOutputNode(),
            $toolNode,
            new AwaitToolResultsNode(),
        ];

        return [...$nodes, ...$this->exitNodes()];
    }

    /**
     * @return Node[]
     */
    protected function exitNodes(): array
    {
        return [new AgentEndNode()];
    }

    /**
     * Hook method for child classes.
     *
     * @return Node[]
     */
    protected function entryNodes(): array
    {
        return [new AgentStartNode()];
    }

    public function getThreadId(): ?string
    {
        return $this->getWorkflowId();
    }

    public function setThreadId(string $threadId): static
    {
        return $this->setWorkflowId($threadId);
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
    public function chat(Message|array $messages = []): AgentState
    {
        $event = $this->startEvent();
        $event->messages = is_array($messages) ? $messages : [$messages];

        return $this->run(ExecutionRequest::start($event));
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
    public function stream(Message|array $messages = []): Generator
    {
        $event = $this->startEvent();
        $event->options->stream = true;
        $event->messages = is_array($messages) ? $messages : [$messages];
        return $this->events(ExecutionRequest::start($event));
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
    ): mixed {
        $event = $this->startEvent();
        $event->options->outputClass = $class ?? $this->getOutputClass();
        $event->options->maxRetries = $maxRetries;
        $event->messages = is_array($messages) ? $messages : [$messages];

        $finalState = $this->run(ExecutionRequest::start($event));

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
        $run = $this->inspect();

        // An answered request stays attached while its tools run, and after they fail.
        if ($run?->status !== WorkflowStatus::Suspended || !$run->interrupt instanceof ApprovalRequest) {
            return [];
        }

        return array_values(array_filter(
            $run->interrupt->getActions(),
            static fn (Action $action): bool => $action->isPending(),
        ));
    }

    /**
     * @return PendingExecution<AgentState>
     * @throws InputTranslationException
     * @throws WorkflowException
     */
    public function submitApprovalDecisions(array $decisions): PendingExecution
    {
        return $this->submitInputs($decisions, new ApprovalTranslator());
    }

    /**
     * @return PendingExecution<AgentState>
     * @throws InputTranslationException
     * @throws WorkflowException
     */
    public function submitToolResults(array $results): PendingExecution
    {
        return $this->submitInputs($results, new ToolResultsTranslator());
    }
}
