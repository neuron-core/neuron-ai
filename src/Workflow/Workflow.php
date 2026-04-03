<?php

declare(strict_types=1);

namespace NeuronAI\Workflow;

use Generator;
use Inspector\Exceptions\InspectorException;
use NeuronAI\Exceptions\WorkflowException;
use NeuronAI\Observability\EventBus;
use NeuronAI\Observability\Events\AgentError;
use NeuronAI\Observability\Events\WorkflowEnd;
use NeuronAI\Observability\Events\WorkflowStart;
use NeuronAI\Observability\ObserverInterface;
use NeuronAI\StaticConstructor;
use NeuronAI\Workflow\Events\Event;
use NeuronAI\Workflow\Events\StartEvent;
use NeuronAI\Workflow\Execution\SequentialExecutor;
use NeuronAI\Workflow\Execution\WorkflowExecutorInterface;
use NeuronAI\Workflow\Exporter\ConsoleExporter;
use NeuronAI\Workflow\Exporter\ExporterInterface;
use NeuronAI\Workflow\Interrupt\InterruptRequest;
use NeuronAI\Workflow\Interrupt\WorkflowInterrupt;
use NeuronAI\Workflow\Persistence\InMemoryPersistence;
use NeuronAI\Workflow\Persistence\PersistenceInterface;
use ReflectionClass;
use ReflectionException;
use ReflectionNamedType;
use ReflectionType;
use ReflectionUnionType;
use Throwable;

use function array_merge;
use function count;
use function is_a;
use function is_array;
use function is_null;
use function uniqid;

/**
 * @method static static make(?PersistenceInterface $persistence = null, ?string $resumeToken = null, ?WorkflowState $state = null, ?WorkflowExecutorInterface $executor = null)
 */
class Workflow implements WorkflowInterface
{
    use StaticConstructor;
    use HandleMiddleware;
    use ResolveState;

    /**
     * @var NodeInterface[]
     */
    protected array $nodes = [];

    /**
     * @var array<class-string, NodeInterface>
     */
    protected array $eventNodeMap = [];

    protected ExporterInterface $exporter;

    protected string $workflowId;

    protected Event $startEvent;

    protected WorkflowExecutorInterface $executor;

    /**
     * @throws WorkflowException
     */
    public function __construct(
        protected ?PersistenceInterface $persistence = null,
        ?string $resumeToken = null,
        protected ?WorkflowState $state = null,
    ) {
        $this->exporter = new ConsoleExporter();

        if (is_null($persistence) && !is_null($resumeToken)) {
            throw new WorkflowException('Persistence must be defined when resumeToken is defined');
        }

        $this->persistence = $persistence ?? new InMemoryPersistence();
        $this->workflowId = $resumeToken ?? uniqid('workflow_');
        $this->executor = new SequentialExecutor();

        // Register the node middleware
        $this->addGlobalMiddleware($this->globalMiddleware());
        foreach ($this->middleware() as $node => $middleware) {
            $middleware = is_array($middleware) ? $middleware : [$middleware];
            $this->addMiddleware($node, $middleware);
        }
    }

    /**
     * Register an observer to receive events for this workflow.
     *
     * Observers are scoped to the workflow instance, ensuring proper
     * isolation when running multiple workflows concurrently.
     */
    public function observe(ObserverInterface $observer): self
    {
        EventBus::observe($observer, $this->workflowId);
        return $this;
    }

    /**
     * Configure workflow persistence.
     */
    public function setPersistence(PersistenceInterface $persistence, ?string $resumeToken = null): self
    {
        $this->persistence = $persistence;

        if ($resumeToken !== null) {
            $this->workflowId = $resumeToken;
        }

        return $this;
    }

    /**
     * @deprecated Use init() instead.
     */
    public function start(?InterruptRequest $resumeRequest = null): WorkflowHandler
    {
        return new WorkflowHandler($this, $resumeRequest);
    }

    /**
     * Initialize the workflow handler.
     */
    public function init(?InterruptRequest $resumeRequest = null): WorkflowHandlerInterface
    {
        return new WorkflowHandler($this, $resumeRequest);
    }

    /**
     * Set a custom start event with initial data.
     */
    public function setStartEvent(Event $event): self
    {
        $this->startEvent = $event;
        return $this;
    }

    /**
     * Create the default start event for the workflow.
     */
    protected function startEvent(): Event
    {
        return new StartEvent();
    }

    /**
     * Resolve the start event for the workflow.
     */
    protected function resolveStartEvent(): Event
    {
        return $this->startEvent ??= $this->startEvent();
    }

    /**
     * @throws WorkflowInterrupt|WorkflowException|Throwable
     */
    public function run(): Generator
    {
        EventBus::emit('workflow-start', $this, new WorkflowStart($this->eventNodeMap), $this->workflowId);

        // Store workflow ID in state for nodes to access when emitting events
        $this->resolveState()->set('__workflowId', $this->workflowId);

        $this->bootstrap();

        $startEvent = $this->resolveStartEvent();
        yield from $this->execute($startEvent, $this->eventNodeMap[$startEvent::class]);

        EventBus::emit('workflow-end', $this, new WorkflowEnd($this->resolveState()), $this->workflowId);
        EventBus::clear($this->workflowId);

        return $this->resolveState();
    }

    /**
     * @throws WorkflowInterrupt|WorkflowException|Throwable
     */
    public function resume(InterruptRequest $resumeRequest): Generator
    {
        EventBus::emit('workflow-resume', $this, new WorkflowStart($this->eventNodeMap), $this->workflowId);

        // Store workflow ID in state for nodes to access when emitting events
        $this->resolveState()->set('__workflowId', $this->workflowId);

        $this->bootstrap();

        $interrupt = $this->persistence->load($this->workflowId);
        $this->setState($interrupt->getState());

        yield from $this->execute(
            $interrupt->getEvent(),
            $interrupt->getNode(),
            $resumeRequest
        );

        $this->workflowEnd();

        return $this->resolveState();
    }

    /**
     * @throws WorkflowInterrupt|WorkflowException|Throwable
     */
    protected function execute(
        Event $currentEvent,
        NodeInterface $currentNode,
        ?InterruptRequest $resumeRequest = null
    ): Generator {
        // Delegate to executor
        yield from $this->getExecutor()->execute(
            $this,
            $currentEvent,
            $currentNode,
            $resumeRequest
        );
    }

    /**
     * @throws InspectorException
     */
    protected function workflowEnd(): void
    {
        EventBus::emit('workflow-end', $this, new WorkflowEnd($this->resolveState()), $this->workflowId);
        EventBus::clear($this->workflowId);
    }

    /**
     * @return NodeInterface[]
     */
    protected function nodes(): array
    {
        return [];
    }

    public function addNode(NodeInterface $node): Workflow
    {
        $this->nodes[] = $node;

        return $this;
    }

    /**
     * @param NodeInterface[] $nodes
     */
    public function addNodes(array $nodes): Workflow
    {
        foreach ($nodes as $node) {
            $this->addNode($node);
        }

        return $this;
    }

    /**
     * @return NodeInterface[]
     */
    protected function getNodes(): array
    {
        return array_merge($this->nodes(), $this->nodes);
    }

    /**
     * @throws WorkflowException
     * @throws InspectorException
     */
    protected function bootstrap(): void
    {
        try {
            $this->loadEventNodeMap();
            $this->validate();
        } catch (Throwable $exception) {
            EventBus::emit('error', $this, new AgentError($exception), $this->workflowId);
            throw $exception;
        }
    }

    /**
     * @throws WorkflowException
     */
    protected function loadEventNodeMap(): void
    {
        $this->eventNodeMap = [];

        foreach ($this->getNodes() as $node) {
            if (!$node instanceof NodeInterface) {
                throw new WorkflowException('All nodes must implement ' . NodeInterface::class);
            }

            $this->validateInvokeMethodSignature($node);

            try {
                $reflection = new ReflectionClass($node);
                $method = $reflection->getMethod('__invoke');
                $parameters = $method->getParameters();
                $firstParam = $parameters[0];
                $firstParamType = $firstParam->getType();

                if ($firstParamType instanceof ReflectionNamedType) {
                    $eventClass = $firstParamType->getName();

                    if (isset($this->eventNodeMap[$eventClass])) {
                        throw new WorkflowException("Node for event {$eventClass} already exists");
                    }

                    $this->eventNodeMap[$eventClass] = $node;
                }
            } catch (ReflectionException $e) {
                throw new WorkflowException('Failed to load event-node map for '.$node::class.': ' . $e->getMessage(), $e->getCode(), $e);
            }
        }
    }

    public function getEventNodeMap(): array
    {
        return $this->eventNodeMap;
    }

    /**
     * Check if a node exists for the given event class.
     */
    public function hasNodeForEvent(string $eventClass): bool
    {
        return isset($this->eventNodeMap[$eventClass]);
    }

    /**
     * Get the node that handles a specific event type.
     */
    public function getNodeForEvent(string $eventClass): ?NodeInterface
    {
        return $this->eventNodeMap[$eventClass] ?? null;
    }

    /**
     * Set a custom executor for workflow execution.
     */
    public function setExecutor(WorkflowExecutorInterface $executor): self
    {
        $this->executor = $executor;
        return $this;
    }

    /**
     * Get the current executor.
     */
    public function getExecutor(): WorkflowExecutorInterface
    {
        return $this->executor;
    }

    /**
     * Get the workflow ID.
     */
    public function getWorkflowId(): string
    {
        return $this->workflowId;
    }

    /**
     * Delete persisted state.
     */
    public function deletePersistedState(): void
    {
        $this->persistence->delete($this->workflowId);
    }

    /**
     * Persist an interrupt.
     */
    public function persistInterrupt(WorkflowInterrupt $interrupt): void
    {
        $this->persistence->save($this->workflowId, $interrupt);
    }

    /**
     * Emit workflow end event.
     */
    public function emitWorkflowEnd(): void
    {
        $this->workflowEnd();
    }

    /**
     * @throws WorkflowException
     */
    protected function validate(): void
    {
        $startEvent = $this->resolveStartEvent();
        $startEventClass = $startEvent::class;

        if (!isset($this->eventNodeMap[$startEventClass])) {
            throw new WorkflowException('No nodes found that handle '.$startEventClass);
        }
    }

    /**
     * @throws WorkflowException
     */
    protected function validateInvokeMethodSignature(NodeInterface $node): void
    {
        try {
            $reflection = new ReflectionClass($node);

            if (!$reflection->hasMethod('__invoke')) {
                throw new WorkflowException('Failed to validate '.$node::class.': Missing __invoke method');
            }

            $method = $reflection->getMethod('__invoke');
            $parameters = $method->getParameters();

            if (count($parameters) !== 2) {
                throw new WorkflowException('Failed to validate '.$node::class.': __invoke method must have exactly 2 parameters');
            }

            $firstParam = $parameters[0];
            $secondParam = $parameters[1];

            if (!$firstParam->hasType() || !$firstParam->getType() instanceof ReflectionType) {
                throw new WorkflowException('Failed to validate '.$node::class.': First parameter of __invoke method must have a type declaration');
            }

            if (!$secondParam->hasType() || !$secondParam->getType() instanceof ReflectionType) {
                throw new WorkflowException('Failed to validate '.$node::class.': Second parameter of __invoke method must have a type declaration');
            }

            $firstParamType = $firstParam->getType();
            $secondParamType = $secondParam->getType();

            if ($firstParamType instanceof ReflectionUnionType) {
                throw new WorkflowException('Failed to validate '.$node::class.': Nodes can handle only one event type.');
            }

            if (!($firstParamType instanceof ReflectionNamedType) || !is_a($firstParamType->getName(), Event::class, true)) {
                throw new WorkflowException('Failed to validate '.$node::class.': First parameter of __invoke method must be a type that implements ' . Event::class);
            }

            if (!($secondParamType instanceof ReflectionNamedType) || !is_a($secondParamType->getName(), WorkflowState::class, true)) {
                throw new WorkflowException('Failed to validate '.$node::class.': Second parameter of __invoke method must be ' . WorkflowState::class);
            }

            $returnType = $method->getReturnType();

            if ($returnType instanceof ReflectionNamedType) {
                // Handle single return types
                if (!is_a($returnType->getName(), Event::class, true) && !is_a($returnType->getName(), Generator::class, true)) {
                    throw new WorkflowException('Failed to validate '.$node::class.': __invoke method must return a type that implements ' . Event::class);
                }
            } elseif ($returnType instanceof ReflectionUnionType) {
                // Handle union return type - all types must implement Event interface or be a Generator
                foreach ($returnType->getTypes() as $type) {
                    if (
                        !($type instanceof ReflectionNamedType) ||
                        (!is_a($type->getName(), Event::class, true) && !is_a($type->getName(), Generator::class, true))
                    ) {
                        throw new WorkflowException('Failed to validate '.$node::class.': All return types in union must implement ' . Event::class);
                    }
                }
            } else {
                throw new WorkflowException('Failed to validate '.$node::class.': __invoke method must return a type that implements ' . Event::class);
            }

        } catch (ReflectionException $e) {
            throw new WorkflowException('Failed to validate '.$node::class.': ' . $e->getMessage(), $e->getCode(), $e);
        }
    }

    public function getResumeToken(): string
    {
        return $this->workflowId;
    }

    /**
     * @throws WorkflowException|InspectorException
     */
    public function export(): string
    {
        if ($this->eventNodeMap === []) {
            $this->bootstrap();
        }

        return $this->exporter->export($this->eventNodeMap);
    }

    public function setExporter(ExporterInterface $exporter): Workflow
    {
        $this->exporter = $exporter;
        return $this;
    }
}
