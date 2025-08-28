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
use NeuronAI\Workflow\Exporter\ExporterInterface;
use NeuronAI\Workflow\Exporter\MermaidExporter;
use NeuronAI\Workflow\Persistence\InMemoryPersistence;
use NeuronAI\Workflow\Persistence\PersistenceInterface;
use SplSubject;

/**
 * @method static static make(?PersistenceInterface $persistence = null, ?string $workflowId = null)
 */
class Workflow implements SplSubject
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

    protected string $workflowId;

    public function __construct(?PersistenceInterface $persistence = null, ?string $workflowId = null)
    {
        $this->exporter = new MermaidExporter();

        if (\is_null($persistence) && !\is_null($workflowId)) {
            throw new WorkflowException('Persistence must be defined when workflowId is defined');
        }
        if (\is_null($workflowId) && !\is_null($persistence)) {
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
            throw new WorkflowException('No nodes found that accept StartEvent');
        }
    }

    /**
     * @throws WorkflowInterrupt|WorkflowException|\Throwable
     */
    protected function execute(
        Event $currentEvent,
        string $currentNodeClass,
        WorkflowState $state,
        bool $resuming = false,
        array|string|int $humanFeedback = []
    ): WorkflowState {
        $context = new WorkflowContext(
            $this->workflowId,
            $currentNodeClass,
            $this->persistence,
            $state,
            $currentEvent
        );

        if ($resuming) {
            $context->setResuming(true, [$currentNodeClass => $humanFeedback]);
        }

        try {
            while (!($currentEvent instanceof StopEvent)) {
                $node = $this->eventNodeMap[$currentNodeClass] ?? null;
                if (!$node) {
                    throw new WorkflowException("No node found for event class: {$currentNodeClass}");
                }
                $node->setContext($context);

                $this->notify('workflow-node-start', new WorkflowNodeStart($currentNodeClass, $state));
                try {
                    $currentEvent = $node->run($currentEvent, $state);
                } catch (\Throwable $exception) {
                    $this->notify('error', new AgentError($exception));
                    throw $exception;
                }
                $this->notify('workflow-node-end', new WorkflowNodeEnd($currentNodeClass, $state));

                if ($currentEvent instanceof StopEvent) {
                    break;
                }

                $nextEventClass = get_class($currentEvent);
                if (!isset($this->eventNodeMap[$nextEventClass])) {
                    throw new WorkflowException("No node found that accepts event: " . $nextEventClass);
                }

                $currentNodeClass = $nextEventClass;

                // Update the context before the next iteration
                $context = new WorkflowContext(
                    $this->workflowId,
                    $currentNodeClass,
                    $this->persistence,
                    $state,
                    $currentEvent
                );
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
     * @throws WorkflowInterrupt|WorkflowException|\Throwable
     */
    public function run(?WorkflowState $initialState = null): WorkflowState
    {
        $this->notify('workflow-start', new WorkflowStart($this->getNodes(), []));
        try {
            $this->validate();
        } catch (WorkflowException $exception) {
            $this->notify('error', new AgentError($exception));
            throw $exception;
        }

        $state = $initialState ?? new WorkflowState();
        $startEventClass = StartEvent::class;

        $state = $this->execute(new StartEvent(), $startEventClass, $state);
        $this->notify('workflow-end', new WorkflowEnd($state));

        return $state;
    }

    /**
     * @throws WorkflowInterrupt|WorkflowException|\Throwable
     */
    public function resume(array|string|int $humanFeedback): WorkflowState
    {
        $this->notify('workflow-resume', new WorkflowStart($this->getNodes(), []));
        $interrupt = $this->persistence->load($this->workflowId);

        $state = $interrupt->getState();
        $currentNode = $interrupt->getCurrentNode();
        $currentEvent = $interrupt->getCurrentEvent() ?? new StartEvent();

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
     * @return NodeInterface[]
     */
    protected function nodes(): array
    {
        return [];
    }

    public function addNode(NodeInterface $node): self
    {
        $this->nodes[$node::class] = $node;
        $this->eventNodeMap = []; // Reset cache
        return $this;
    }

    /**
     * @param array<string, string|NodeInterface> $eventToNodeMap Array where keys are Event class names and values are Node class names or instances
     */
    public function addNodes(array $eventToNodeMap): Workflow
    {
        $this->eventNodeMap = [];
        $this->nodes = [];

        foreach ($eventToNodeMap as $eventClass => $node) {
            if (!is_string($eventClass) || !class_exists($eventClass) || !is_a($eventClass, Event::class, true)) {
                throw new WorkflowException("Event class {$eventClass} must implement Event interface");
            }

            if (is_string($node)) {
                $node = new $node();
            }

            if (!$node instanceof NodeInterface) {
                throw new WorkflowException("Node must implement NodeInterface");
            }

            $this->eventNodeMap[$eventClass] = $node;
            $this->nodes[get_class($node)] = $node;
        }

        return $this;
    }

    /**
     * @return array<string, NodeInterface>
     */
    public function getNodes(): array
    {
        if ($this->nodes === []) {
            foreach ($this->nodes() as $node) {
                $this->addNode($node);
            }
        }

        return $this->nodes;
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

}
