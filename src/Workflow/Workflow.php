<?php

declare(strict_types=1);

namespace NeuronAI\Workflow;

use Generator;
use NeuronAI\Exceptions\WorkflowException;
use NeuronAI\Observability\EventBus;
use NeuronAI\Observability\ObserverInterface;
use NeuronAI\StaticConstructor;
use NeuronAI\Workflow\Events\Event;
use NeuronAI\Workflow\Events\StartEvent;
use NeuronAI\Workflow\Executor\WorkflowExecutor;
use NeuronAI\Workflow\Executor\WorkflowExecutorInterface;
use NeuronAI\Workflow\Exporter\ConsoleExporter;
use NeuronAI\Workflow\Exporter\ExporterInterface;
use NeuronAI\Workflow\Interrupt\InterruptRequest;
use NeuronAI\Workflow\Persistence\PersistenceInterface;
use ReflectionClass;
use ReflectionException;
use ReflectionIntersectionType;
use ReflectionNamedType;
use ReflectionType;
use ReflectionUnionType;

use function array_filter;
use function array_merge;
use function count;
use function is_a;
use function is_array;
use function reset;
use function uniqid;

/**
 * @method static static make(?string $resumeToken = null, ?WorkflowState $state = null)
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

    protected ?WorkflowExecutorInterface $executor = null;

    protected ?PersistenceInterface $persistence = null;

    /**
     * @throws WorkflowException
     */
    public function __construct(
        ?string $resumeToken = null,
        protected ?WorkflowState   $state = null,
    ) {
        $this->exporter = new ConsoleExporter();
        $this->workflowId = $resumeToken ?? uniqid('workflow_');

        $this->addGlobalMiddleware($this->globalMiddleware());
        foreach ($this->middleware() as $node => $middleware) {
            $middleware = is_array($middleware) ? $middleware : [$middleware];
            $this->addMiddleware($node, $middleware);
        }
    }

    /**
     * Register an observer to receive scoped events for this workflow.
     */
    public function observe(ObserverInterface $observer): WorkflowInterface
    {
        EventBus::observe($observer, $this->workflowId);
        return $this;
    }

    /**
     * Set a custom executor for this workflow.
     */
    public function setExecutor(WorkflowExecutorInterface $executor): static
    {
        $this->executor = $executor;
        return $this;
    }

    /**
     * Enable durability by providing a persistence backend.
     */
    public function setPersistence(PersistenceInterface $persistence): static
    {
        $this->persistence = $persistence;
        return $this;
    }

    /**
     * Resolve the executor, creating a default if none was configured.
     */
    public function resolveExecutor(): WorkflowExecutorInterface
    {
        if ($this->executor instanceof \NeuronAI\Workflow\Executor\WorkflowExecutorInterface) {
            return $this->executor;
        }

        $stepEngine = $this->persistence instanceof PersistenceInterface
            ? new Executor\LocalStepEngine(persistence: $this->persistence)
            : new Executor\LocalStepEngine();

        return $this->executor = new WorkflowExecutor($stepEngine);
    }

    /**
     * Run the workflow to completion, consuming the generator internally.
     */
    public function run(?InterruptRequest $interrupt = null): WorkflowState
    {
        $generator = $this->events($interrupt);

        return $generator->getReturn();
    }

    /**
     * Execute the workflow, yielding events in real time.
     *
     * @return Generator<int, Event, mixed, WorkflowState>
     */
    public function events(?InterruptRequest $interrupt = null): Generator
    {
        yield from $this->resolveExecutor()->execute($this, $interrupt);

        return $this->resolveState();
    }

    /**
     * Bootstrap the workflow (load event-node map, validate).
     *
     * @throws WorkflowException
     */
    public function bootstrap(): static
    {
        $this->loadEventNodeMap();
        $this->validate();
        return $this;
    }

    /**
     * Get the resolved start event for the workflow.
     */
    public function getStartEvent(): Event
    {
        return $this->resolveStartEvent();
    }

    /**
     * Set a custom start event with initial data.
     */
    public function setStartEvent(Event $event): WorkflowInterface
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
     * @return NodeInterface[]
     */
    protected function nodes(): array
    {
        return [];
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

                $eventClass = null;

                if ($firstParamType instanceof ReflectionNamedType) {
                    $eventClass = $firstParamType->getName();
                } elseif ($firstParamType instanceof ReflectionIntersectionType) {
                    $eventTypes = array_filter(
                        $firstParamType->getTypes(),
                        fn (ReflectionType $type): bool => $type instanceof ReflectionNamedType && is_a($type->getName(), Event::class, true)
                    );
                    $firstEventType = reset($eventTypes);
                    $eventClass = $firstEventType instanceof ReflectionNamedType ? $firstEventType->getName() : null;
                }

                if (isset($eventClass)) {
                    if (isset($this->eventNodeMap[$eventClass])) {
                        throw new WorkflowException("Node for event {$eventClass} already exists");
                    }

                    $this->eventNodeMap[$eventClass] = $node;
                }
            } catch (ReflectionException $e) {
                throw new WorkflowException('Failed to load event-node map for ' . $node::class . ': ' . $e->getMessage(), $e->getCode(), $e);
            }
        }
    }

    public function getEventNodeMap(): array
    {
        return $this->eventNodeMap;
    }

    /**
     * Get the node that handles a specific event type.
     *
     * @throws WorkflowException if no node is registered for the given event class
     */
    public function getNodeForEvent(string $eventClass): NodeInterface
    {
        if (!isset($this->eventNodeMap[$eventClass])) {
            throw new WorkflowException(
                "No node found that handle event: " . $eventClass
            );
        }

        return $this->eventNodeMap[$eventClass];
    }

    /**
     * Get the workflow ID.
     */
    public function getWorkflowId(): string
    {
        return $this->workflowId;
    }

    /**
     * @throws WorkflowException
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

    /**
     * @throws WorkflowException
     */
    protected function validate(): void
    {
        $startEvent = $this->resolveStartEvent();
        $startEventClass = $startEvent::class;

        if (!isset($this->eventNodeMap[$startEventClass])) {
            throw new WorkflowException('No nodes found that handle ' . $startEventClass);
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
                throw new WorkflowException('Failed to validate ' . $node::class . ': Missing __invoke method');
            }

            $method = $reflection->getMethod('__invoke');
            $parameters = $method->getParameters();

            if (count($parameters) !== 2) {
                throw new WorkflowException('Failed to validate ' . $node::class . ': __invoke method must have exactly 2 parameters');
            }

            $firstParam = $parameters[0];
            $secondParam = $parameters[1];

            if (!$firstParam->hasType() || !$firstParam->getType() instanceof ReflectionType) {
                throw new WorkflowException('Failed to validate ' . $node::class . ': First parameter of __invoke method must have a type declaration');
            }

            if (!$secondParam->hasType() || !$secondParam->getType() instanceof ReflectionType) {
                throw new WorkflowException('Failed to validate ' . $node::class . ': Second parameter of __invoke method must have a type declaration');
            }

            $firstParamType = $firstParam->getType();
            $secondParamType = $secondParam->getType();

            if ($firstParamType instanceof ReflectionUnionType) {
                throw new WorkflowException('Failed to validate ' . $node::class . ': Nodes can handle only one event type.');
            }

            if ($firstParamType instanceof ReflectionIntersectionType) {
                $eventTypes = array_filter(
                    $firstParamType->getTypes(),
                    fn (ReflectionType $type): bool => $type instanceof ReflectionNamedType && is_a($type->getName(), Event::class, true)
                );

                if (count($eventTypes) !== 1) {
                    throw new WorkflowException('Failed to validate ' . $node::class . ': Intersection type must contain exactly one type that implements ' . Event::class);
                }
            }

            if (!($firstParamType instanceof ReflectionNamedType) || !is_a($firstParamType->getName(), Event::class, true)) {
                throw new WorkflowException('Failed to validate ' . $node::class . ': First parameter of __invoke method must be a type that implements ' . Event::class);
            }

            if (!($secondParamType instanceof ReflectionNamedType) || !is_a($secondParamType->getName(), WorkflowState::class, true)) {
                throw new WorkflowException('Failed to validate ' . $node::class . ': Second parameter of __invoke method must be ' . WorkflowState::class);
            }

            $returnType = $method->getReturnType();

            if ($returnType instanceof ReflectionNamedType) {
                if (!is_a($returnType->getName(), Event::class, true) && !is_a($returnType->getName(), Generator::class, true)) {
                    throw new WorkflowException('Failed to validate ' . $node::class . ': __invoke method must return a type that implements ' . Event::class);
                }
            } elseif ($returnType instanceof ReflectionUnionType) {
                foreach ($returnType->getTypes() as $type) {
                    if (
                        !($type instanceof ReflectionNamedType) ||
                        (!is_a($type->getName(), Event::class, true) && !is_a($type->getName(), Generator::class, true))
                    ) {
                        throw new WorkflowException('Failed to validate ' . $node::class . ': All return types in union must implement ' . Event::class);
                    }
                }
            } else {
                throw new WorkflowException('Failed to validate ' . $node::class . ': __invoke method must return a type that implements ' . Event::class);
            }
        } catch (ReflectionException $e) {
            throw new WorkflowException('Failed to validate ' . $node::class . ': ' . $e->getMessage(), $e->getCode(), $e);
        }
    }
}
