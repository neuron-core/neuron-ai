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
use NeuronAI\Workflow\Events\Event;
use NeuronAI\Workflow\Events\StartEvent;
use NeuronAI\Workflow\Events\StopEvent;
use NeuronAI\Workflow\Exporter\ConsoleExporter;
use NeuronAI\Workflow\Exporter\ExporterInterface;
use NeuronAI\Workflow\Interrupt\InterruptRequest;
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
    use HandleMiddleware;

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

    protected Event $startEvent;

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
     */
    public function start(?InterruptRequest $resumeRequest = null): WorkflowHandler
    {
        $isResume = $resumeRequest instanceof \NeuronAI\Workflow\Interrupt\InterruptRequest;
        return new WorkflowHandler($this, $isResume, $resumeRequest);
    }

    /**
     * Set a custom start event with initial data.
     * Use this with the builder pattern to provide initial workflow data.
     *
     * @param Event $event Custom start event instance
     */
    public function withStartEvent(Event $event): self
    {
        $this->startEvent = $event;
        return $this;
    }

    /**
     * Resolve the start event for this workflow.
     * Uses lazy initialization: checks property, falls back to startEvent() factory method.
     *
     * @return Event The start event instance
     */
    protected function resolveStartEvent(): Event
    {
        return $this->startEvent ?? $this->startEvent = $this->startEvent();
    }

    /**
     * Create the default start event for this workflow.
     * Override this method to provide a custom start event with initial data.
     *
     * @return Event The start event instance
     */
    protected function startEvent(): Event
    {
        return new StartEvent();
    }

    /**
     * Run the middleware pipeline around node execution.
     *
     * Executes all middleware before() methods, then the node, then all middleware after() methods.
     */
    protected function runMiddlewarePipeline(Event $event, NodeInterface $node, WorkflowState $state): Event|Generator
    {
        $middleware = $this->getMiddlewareForNode($node);

        // Execute all before() methods in registration order
        foreach ($middleware as $m) {
            $m->before($node, $event, $state);
        }

        // Execute the node
        $result = $node->run($event, $state);

        // Execute all after() methods in registration order
        foreach ($middleware as $m) {
            $m->after($node, $event, $result, $state);
        }

        return $result;
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

        $startEvent = $this->resolveStartEvent();
        yield from $this->execute($startEvent, $this->eventNodeMap[$startEvent::class]);

        $this->notify('workflow-end', new WorkflowEnd($this->state));

        return $this->state;
    }

    /**
     * @throws WorkflowInterrupt|WorkflowException|\Throwable
     */
    public function resume(InterruptRequest $resumeRequest): \Generator
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

        yield from $this->execute(
            $interrupt->getCurrentEvent(),
            $interrupt->getNode(),
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
            $this->persistence->save($this->workflowId, $interrupt);
            $this->notify('workflow-interrupt', $interrupt);
            throw $interrupt;
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

        // Propagate callbacks to the node so it can emit events
        $this->propagateCallbacks($node);

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

    public function getEventNodeMap(): array
    {
        return $this->eventNodeMap;
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
