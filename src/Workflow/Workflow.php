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
use ReflectionMethod;
use SplSubject;

/**
 * @method static static make(?PersistenceInterface $persistence = null, ?string $workflowId = null)
 */
class Workflow implements SplSubject
{
    use Observable;
    use StaticConstructor;

    /**
     * @var array<string, NodeInterface>
     */
    protected array $eventNodeMap = [];

    protected ExporterInterface $exporter;

    protected PersistenceInterface $persistence;

    protected string $workflowId;

    public function __construct(?PersistenceInterface $persistence = null, ?string $workflowId = null)
    {
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
     * @throws WorkflowException
     */
    public function validate(): void
    {
        if (!isset($this->eventNodeMap[StartEvent::class])) {
            throw new WorkflowException('No nodes found that handle '.StartEvent::class);
        }
    }

    /**
     * @throws WorkflowInterrupt|WorkflowException|\Throwable
     */
    public function run(?WorkflowState $initialState = null): WorkflowState
    {
        $this->notify('workflow-start', new WorkflowStart($this->eventNodeMap, []));
        try {
            $this->validate();
        } catch (WorkflowException $exception) {
            $this->notify('error', new AgentError($exception));
            throw $exception;
        }

        $state = $initialState ?? new WorkflowState();

        $state = $this->execute(new StartEvent(), $this->eventNodeMap[StartEvent::class], $state);
        $this->notify('workflow-end', new WorkflowEnd($state));

        return $state;
    }

    /**
     * @throws WorkflowInterrupt|WorkflowException|\Throwable
     */
    public function resume(array|string|int $humanFeedback): WorkflowState
    {
        $this->notify('workflow-resume', new WorkflowStart($this->eventNodeMap, []));
        $interrupt = $this->persistence->load($this->workflowId);

        $state = $interrupt->getState();
        $currentNode = $interrupt->getCurrentNode();
        $currentEvent = $interrupt->getCurrentEvent();

        $result = $this->execute(
            $currentEvent,
            $currentNode,
            $state,
            true,
            $humanFeedback
        );
        $this->notify('workflow-end', new WorkflowEnd($result));

        return  $result;
    }

    /**
     * @throws WorkflowInterrupt|WorkflowException|\Throwable
     */
    protected function execute(
        Event            $currentEvent,
        NodeInterface    $currentNode,
        WorkflowState    $state,
        bool             $resuming = false,
        array|string|int $humanFeedback = []
    ): WorkflowState {
        $feedback = $resuming ? [$currentNode::class => $humanFeedback] : [];

        try {
            while (!($currentEvent instanceof StopEvent)) {
                $currentNode->setWorkflowContext(
                    $state,
                    $currentEvent,
                    $resuming,
                    $feedback
                );

                $this->notify('workflow-node-start', new WorkflowNodeStart($currentNode::class, $state));
                try {
                    $currentEvent = $currentNode->run($currentEvent, $state);
                } catch (\Throwable $exception) {
                    $this->notify('error', new AgentError($exception));
                    throw $exception;
                }
                $this->notify('workflow-node-end', new WorkflowNodeEnd($currentNode::class, $state));

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
            return $state;

        } catch (WorkflowInterrupt $interrupt) {
            $this->persistence->save($this->workflowId, $interrupt);
            $this->notify('workflow-interrupt', $interrupt);
            throw $interrupt;
        }
    }

    /**
     * @throws WorkflowException
     */
    public function addNode(string $eventClass, NodeInterface $node): Workflow
    {
        if (!\class_exists($eventClass) || !\is_a($eventClass, Event::class, true)) {
            throw new WorkflowException("Event class {$eventClass} must implement ".Event::class);
        }

        $this->validateInvokeMethodSignature($node);

        if (isset($this->eventNodeMap[$eventClass])) {
            throw new WorkflowException("Node for event {$eventClass} already exists");
        }

        $this->eventNodeMap[$eventClass] = $node;

        return $this;
    }

    /**
     * @param array<string, string|NodeInterface> $eventToNodeMap Array where keys are Event class names and values are Node instances
     * @throws WorkflowException
     */
    public function addNodes(array $eventToNodeMap): Workflow
    {
        foreach ($eventToNodeMap as $eventClass => $node) {
            $this->addNode($eventClass, $node);
        }

        return $this;
    }

    public function getWorkflowId(): string
    {
        return $this->workflowId;
    }

    public function export(): string
    {
        return $this->exporter->export($this);
    }

    public function setExporter(ExporterInterface $exporter): Workflow
    {
        $this->exporter = $exporter;
        return $this;
    }

    /**
     * @return array<string, NodeInterface>
     */
    public function getEventNodeMap(): array
    {
        return $this->eventNodeMap;
    }

    /**
     * @throws WorkflowException
     */
    private function validateInvokeMethodSignature(NodeInterface $node): void
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

            if (!$firstParam->hasType() || $firstParam->getType() === null) {
                throw new WorkflowException('Failed to validate '.$node::class.': First parameter of __invoke method must have a type declaration');
            }

            if (!$secondParam->hasType() || $secondParam->getType() === null) {
                throw new WorkflowException('Failed to validate '.$node::class.': Second parameter of __invoke method must have a type declaration');
            }

            $firstParamType = $firstParam->getType();
            $secondParamType = $secondParam->getType();

            if (!($firstParamType instanceof \ReflectionNamedType) || !is_a($firstParamType->getName(), Event::class, true)) {
                throw new WorkflowException('Failed to validate '.$node::class.': First parameter of __invoke method must be a type that implements ' . Event::class);
            }

            if (!($secondParamType instanceof \ReflectionNamedType) || $secondParamType->getName() !== WorkflowState::class) {
                throw new WorkflowException('Failed to validate '.$node::class.': Second parameter of __invoke method must be ' . WorkflowState::class);
            }

            $returnType = $method->getReturnType();

            if ($returnType instanceof \ReflectionNamedType) {
                // Handle single return types
                if (!is_a($returnType->getName(), Event::class, true)) {
                    throw new WorkflowException('Failed to validate '.$node::class.': __invoke method must return a type that implements ' . Event::class);
                }
            } elseif ($returnType instanceof \ReflectionUnionType) {
                // Handle union return type - all types must implement Event interface
                foreach ($returnType->getTypes() as $type) {
                    if (!($type instanceof \ReflectionNamedType) || !is_a($type->getName(), Event::class, true)) {
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

}
