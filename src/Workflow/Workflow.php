<?php

declare(strict_types=1);

namespace NeuronAI\Workflow;

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
     * @var array<string, NodeInterface>
     */
    protected array $eventNodeMap = [];

    protected ExporterInterface $exporter;

    protected PersistenceInterface $persistence;

    protected WorkflowState $state;

    protected string $workflowId;

    public function __construct(
        ?WorkflowState $state = null,
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

        $this->state = $state ?? new WorkflowState();
        $this->persistence = $persistence ?? new InMemoryPersistence();
        $this->workflowId = $workflowId ?? \uniqid('neuron_workflow_');
    }

    public function start(
        bool $resume = false,
        mixed $externalFeedback = null
    ): WorkflowHandler {
        return new WorkflowHandler($this, $resume, $externalFeedback);
    }

    public function wakeup(mixed $feedback = null): WorkflowHandler
    {
        return new WorkflowHandler($this, true, $feedback);
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
    public function resume(mixed $externalFeedback): \Generator
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
        $currentNode = $interrupt->getCurrentNode();
        $currentEvent = $interrupt->getCurrentEvent();

        yield from $this->execute(
            $currentEvent,
            $currentNode,
            true,
            $externalFeedback
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
        mixed $externalFeedback = null
    ): \Generator {
        try {
            while (!($currentEvent instanceof StopEvent)) {
                $currentNode->setWorkflowContext(
                    $this->state,
                    $currentEvent,
                    $resuming,
                    $externalFeedback
                );

                $this->notify('workflow-node-start', new WorkflowNodeStart($currentNode::class, $this->state));
                try {
                    $result = $currentNode->run($currentEvent, $this->state);

                    if ($result instanceof \Generator) {
                        foreach ($result as $event) {
                            yield $event;
                        }

                        $currentEvent = $result->getReturn();
                    } else {
                        $currentEvent = $result;
                    }
                } catch (\Throwable $exception) {
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
                $feedback = [];
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
