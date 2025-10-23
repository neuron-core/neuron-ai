<?php

declare(strict_types=1);

namespace NeuronAI\Workflow;

use Generator;
use NeuronAI\Exceptions\WorkflowException;
use NeuronAI\Observability\Events\AgentError;
use NeuronAI\Observability\Events\WorkflowEnd;
use NeuronAI\Observability\Events\WorkflowNodeEnd;
use NeuronAI\Observability\Events\WorkflowNodeStart;
use NeuronAI\Observability\Events\WorkflowStart;
use NeuronAI\Observability\Observable;
use NeuronAI\StaticConstructor;
use NeuronAI\Workflow\Exporter\ConsoleExporter;
use NeuronAI\Workflow\Exporter\ExporterInterface;
use NeuronAI\Workflow\Interrupt\InterruptRequest;
use NeuronAI\Workflow\Middleware\WorkflowMiddleware;
use NeuronAI\Workflow\Persistence\InMemoryPersistence;
use NeuronAI\Workflow\Persistence\PersistenceInterface;
use ReflectionClass;
use ReflectionException;

/**
 * @method static static make(?WorkflowState $state = null, ?PersistenceInterface $persistence = null, ?string $workflowId = null)
 */
class Workflow implements WorkflowInterface
{
    use Observable;
    use StaticConstructor;

    /**
     * @var NodeInterface[]
     */
    protected array $nodes = [];

    /**
     * @var array<class-string, NodeInterface>
     */
    protected array $eventNodeMap = [];

    protected ExporterInterface $exporter;

    protected PersistenceInterface $persistence;

    protected string $workflowId;

    /**
     * Global middleware applied to all events.
     *
     * @var WorkflowMiddleware[]
     */
    protected array $globalMiddleware = [];

    /**
     * Event-specific middleware.
     *
     * @var array<class-string<Event>, WorkflowMiddleware[]>
     */
    protected array $eventMiddleware = [];

    public function __construct(
        protected WorkflowState $state = new WorkflowState(),
        ?PersistenceInterface $persistence = null,
        ?string $workflowId = null
    ) {
        $this->exporter = new ConsoleExporter();

        if (\is_null($persistence) && !\is_null($workflowId)) {
            throw new WorkflowException('Persistence must be defined when workflowId is defined');
        }

        if (!\is_null($persistence) && \is_null($workflowId)) {
            throw new WorkflowException('WorkflowId must be defined when persistence is defined');
        }

        $this->persistence = $persistence ?? new InMemoryPersistence();
        $this->workflowId = $workflowId ?? \uniqid('neuron_workflow_');
    }

    /**
     * Configure workflow persistence.
     *
     * Required when using middleware that interrupts workflows (e.g., ToolApprovalMiddleware).
     *
     * @param PersistenceInterface $persistence Persistence backend
     * @param string $workflowId Unique workflow identifier
     * @return self
     */
    public function setPersistence(PersistenceInterface $persistence, string $workflowId): self
    {
        $this->persistence = $persistence;
        $this->workflowId = $workflowId;
        return $this;
    }

    /**
     * Start or resume the workflow.
     *
     * - No parameter: Fresh start
     * - InterruptRequest parameter: Resume from interruption with user decisions
     *
     * @param InterruptRequest|null $resumeRequest If provided, resumes workflow with these decisions
     * @return WorkflowHandler
     */
    public function start(?InterruptRequest $resumeRequest = null): WorkflowHandler
    {
        $isResume = $resumeRequest !== null;
        return new WorkflowHandler($this, $isResume, $resumeRequest);
    }

    /**
     * Register middleware for the workflow.
     *
     * @param class-string<Event>|WorkflowMiddleware $eventClass Event class or global middleware
     * @param WorkflowMiddleware|WorkflowMiddleware[]|null $middleware Middleware instance(s)
     * @throws WorkflowException
     */
    public function middleware(string|WorkflowMiddleware $eventClass, WorkflowMiddleware|array|null $middleware = null): self
    {
        // Global middleware: middleware($middlewareInstance)
        if ($eventClass instanceof WorkflowMiddleware) {
            $this->globalMiddleware[] = $eventClass;
            return $this;
        }

        // Event-specific middleware: middleware(EventClass::class, $middlewareInstance)
        if ($middleware === null) {
            throw new WorkflowException('Middleware instance must be provided when registering event-specific middleware');
        }

        $middlewareArray = \is_array($middleware) ? $middleware : [$middleware];

        if (!isset($this->eventMiddleware[$eventClass])) {
            $this->eventMiddleware[$eventClass] = [];
        }

        foreach ($middlewareArray as $m) {
            $this->eventMiddleware[$eventClass][] = $m;
        }

        return $this;
    }

    /**
     * Get all registered middleware for the given event.
     *
     * @param Event $event
     * @return WorkflowMiddleware[]
     */
    protected function getMiddlewareForEvent(Event $event): array
    {
        $eventClass = $event::class;
        $eventSpecific = $this->eventMiddleware[$eventClass] ?? [];

        // Combine global and event-specific middleware
        return \array_merge($this->globalMiddleware, $eventSpecific);
    }

    /**
     * Run the middleware pipeline around node execution.
     */
    protected function runMiddlewarePipeline(Event $event, NodeInterface $node, WorkflowState $state): Event|Generator
    {
        $middleware = $this->getMiddlewareForEvent($event);

        // Filter middleware that should handle this event
        $applicableMiddleware = \array_filter(
            $middleware,
            fn (WorkflowMiddleware $m): bool => $m->shouldHandle($event)
        );

        // If no middleware, just run the node
        if ($applicableMiddleware === []) {
            return $node->run($event, $state);
        }

        // Build the middleware pipeline from the inside out
        $pipeline = fn (Event $e): Event|Generator => $node->run($e, $state);

        // Reversely iterate to build the chain
        foreach (\array_reverse($applicableMiddleware) as $middlewareInstance) {
            $pipeline = (fn(Event $e): Event|Generator => $middlewareInstance->handle($e, $state, $pipeline));
        }

        // Execute the pipeline
        return $pipeline($event);
    }

    /**
     * @throws WorkflowInterrupt|WorkflowException|\Throwable
     */
    public function run(): \Generator
    {
        $this->notify('workflow-start', new WorkflowStart($this->eventNodeMap));

        try {
            $this->bootstrap();
        } catch (WorkflowException $exception) {
            $this->notify('error', new AgentError($exception));
            throw $exception;
        }

        yield from $this->execute(new StartEvent(), $this->eventNodeMap[StartEvent::class]);

        $this->notify('workflow-end', new WorkflowEnd($this->state));

        return $this->state;
    }

    /**
     * @throws WorkflowInterrupt|WorkflowException|\Throwable
     */
    public function resume(?Interrupt\InterruptRequest $resumeRequest): \Generator
    {
        $this->notify('workflow-resume', new WorkflowStart($this->eventNodeMap));

        try {
            $this->bootstrap();
        } catch (WorkflowException $exception) {
            $this->notify('error', new AgentError($exception));
            throw $exception;
        }

        $interrupt = $this->persistence->load($this->workflowId);

        $this->state = $interrupt->getState();
        $currentEvent = $interrupt->getCurrentEvent();

        // Derive node from event (deterministic from eventNodeMap)
        $currentNode = $this->eventNodeMap[$currentEvent::class];

        // Restore the checkpoint state to the node
        $currentNode->setCheckpoints($interrupt->getNodeCheckpoints());

        yield from $this->execute(
            $currentEvent,
            $currentNode,
            true,
            $resumeRequest
        );

        $this->notify('workflow-end', new WorkflowEnd($this->state));

        return $this->state;
    }

    /**
     * @throws WorkflowInterrupt|WorkflowException|\Throwable
     */
    protected function execute(
        Event $currentEvent,
        NodeInterface $currentNode,
        bool $resuming = false,
        ?Interrupt\InterruptRequest $resumeRequest = null
    ): \Generator {
        try {
            while (!($currentEvent instanceof StopEvent)) {
                $currentNode->setWorkflowContext(
                    $this->state,
                    $currentEvent,
                    $resuming,
                    $resumeRequest
                );

                $this->notify('workflow-node-start', new WorkflowNodeStart($currentNode::class, $this->state));
                try {
                    // Execute node through the middleware pipeline
                    $result = $this->runMiddlewarePipeline($currentEvent, $currentNode, $this->state);

                    if ($result instanceof \Generator) {
                        foreach ($result as $event) {
                            yield $event;
                        }

                        $currentEvent = $result->getReturn();
                    } else {
                        $currentEvent = $result;
                    }
                } catch (WorkflowInterrupt $interrupt) {
                    // Interruptions are intentional, not errors - let them bubble to outer catch
                    throw $interrupt;
                } catch (\Throwable $exception) {
                    // Only notify for actual errors
                    $this->notify('error', new AgentError($exception));
                    throw $exception;
                }
                $this->notify('workflow-node-end', new WorkflowNodeEnd($currentNode::class, $this->state));

                if ($currentEvent instanceof StopEvent) {
                    break;
                }

                $nextEventClass = $currentEvent::class;
                if (!isset($this->eventNodeMap[$nextEventClass])) {
                    throw new WorkflowException("No node found that handle event: " . $nextEventClass);
                }

                $currentNode = $this->eventNodeMap[$nextEventClass];
                $resuming = false; // Only the first node should be in resuming mode
                $resumeRequest = null;
            }

            $this->persistence->delete($this->workflowId);

        } catch (WorkflowInterrupt $interrupt) {
            // Middleware may throw interrupts without node context
            // Ensure we have the current node's class and checkpoints
            $finalInterrupt = new WorkflowInterrupt(
                $interrupt->getRequest(),
                $currentNode::class,
                $currentNode->getCheckpoints(),
                $this->state,
                $currentEvent
            );

            $this->persistence->save($this->workflowId, $finalInterrupt);
            $this->notify('workflow-interrupt', $finalInterrupt);
            throw $finalInterrupt;
        }
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
        return \array_merge($this->nodes(), $this->nodes);
    }

    /**
     * @throws WorkflowException
     */
    protected function bootstrap(): void
    {
        $this->loadEventNodeMap();
        $this->validate();
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

                if ($firstParamType instanceof \ReflectionNamedType) {
                    $eventClass = $firstParamType->getName();

                    if (isset($this->eventNodeMap[$eventClass])) {
                        throw new WorkflowException("Node for event {$eventClass} already exists");
                    }

                    $this->eventNodeMap[$eventClass] = $node;
                }
            } catch (ReflectionException $e) {
                throw new WorkflowException('Failed to load event-node map for '.$node::class.': ' . $e->getMessage());
            }
        }
    }

    /**
     * @throws WorkflowException
     */
    protected function validate(): void
    {
        if (!isset($this->eventNodeMap[StartEvent::class])) {
            throw new WorkflowException('No nodes found that handle '.StartEvent::class);
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

            if (\count($parameters) !== 2) {
                throw new WorkflowException('Failed to validate '.$node::class.': __invoke method must have exactly 2 parameters');
            }

            $firstParam = $parameters[0];
            $secondParam = $parameters[1];

            if (!$firstParam->hasType() || !$firstParam->getType() instanceof \ReflectionType) {
                throw new WorkflowException('Failed to validate '.$node::class.': First parameter of __invoke method must have a type declaration');
            }

            if (!$secondParam->hasType() || !$secondParam->getType() instanceof \ReflectionType) {
                throw new WorkflowException('Failed to validate '.$node::class.': Second parameter of __invoke method must have a type declaration');
            }

            $firstParamType = $firstParam->getType();
            $secondParamType = $secondParam->getType();

            if ($firstParamType instanceof \ReflectionUnionType) {
                throw new WorkflowException('Failed to validate '.$node::class.': Nodes can handle only one event type.');
            }

            if (!($firstParamType instanceof \ReflectionNamedType) || !\is_a($firstParamType->getName(), Event::class, true)) {
                throw new WorkflowException('Failed to validate '.$node::class.': First parameter of __invoke method must be a type that implements ' . Event::class);
            }

            if (!($secondParamType instanceof \ReflectionNamedType) || !\is_a($secondParamType->getName(), WorkflowState::class, true)) {
                throw new WorkflowException('Failed to validate '.$node::class.': Second parameter of __invoke method must be ' . WorkflowState::class);
            }

            $returnType = $method->getReturnType();

            if ($returnType instanceof \ReflectionNamedType) {
                // Handle single return types
                if (!\is_a($returnType->getName(), Event::class, true) && !\is_a($returnType->getName(), \Generator::class, true)) {
                    throw new WorkflowException('Failed to validate '.$node::class.': __invoke method must return a type that implements ' . Event::class);
                }
            } elseif ($returnType instanceof \ReflectionUnionType) {
                // Handle union return type - all types must implement Event interface or be a Generator
                foreach ($returnType->getTypes() as $type) {
                    if (
                        !($type instanceof \ReflectionNamedType) ||
                        (!\is_a($type->getName(), Event::class, true) && !\is_a($type->getName(), \Generator::class, true))
                    ) {
                        throw new WorkflowException('Failed to validate '.$node::class.': All return types in union must implement ' . Event::class);
                    }
                }
            } else {
                throw new WorkflowException('Failed to validate '.$node::class.': __invoke method must return a type that implements ' . Event::class);
            }

        } catch (ReflectionException $e) {
            throw new WorkflowException('Failed to validate '.$node::class.': ' . $e->getMessage());
        }
    }

    public function getEventNodeMap(): array
    {
        return $this->eventNodeMap;
    }

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
}
