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
use ReflectionClass;
use ReflectionMethod;
use ReflectionUnionType;
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
     * @var array<string, array<string, NodeInterface>>
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
        $this->buildEventNodeMap();

        if (!isset($this->eventNodeMap[StartEvent::class])) {
            throw new WorkflowException('No nodes found that accept StartEvent');
        }

        if (count($this->eventNodeMap[StartEvent::class]) > 1) {
            throw new WorkflowException('Multiple nodes found that accept StartEvent. Only one start node is allowed.');
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
                $node = $this->nodes[$currentNodeClass];
                $node->setContext($context);

                $this->notify('workflow-node-start', new WorkflowNodeStart($currentNodeClass, $state));
                try {
                    $currentEvent = $node->run($currentEvent, $state);
                } catch (WorkflowInterrupt $interrupt) {
                    throw $interrupt;
                } catch (\Throwable $exception) {
                    $this->notify('error', new AgentError($exception));
                    throw $exception;
                }
                $this->notify('workflow-node-end', new WorkflowNodeEnd($currentNodeClass, $state));

                if ($currentEvent instanceof StopEvent) {
                    break;
                }

                $nextNodeClass = $this->findNextNode($currentEvent);
                if ($nextNodeClass === null) {
                    throw new WorkflowException("No node found that accepts event: " . get_class($currentEvent));
                }

                $currentNodeClass = $nextNodeClass;

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
        $startNodeClass = array_keys($this->eventNodeMap[StartEvent::class])[0];

        $state = $this->execute(new StartEvent(), $startNodeClass, $state);
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
     * @param NodeInterface[]|string[] $nodes
     */
    public function addNodes(array $nodes): Workflow
    {
        foreach ($nodes as $node) {
            if (is_string($node)) {
                $node = new $node();
            }
            $this->addNode($node);
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

    private function findNextNode(Event $event): ?string
    {
        $eventClass = get_class($event);

        // First try the exact match
        if (isset($this->eventNodeMap[$eventClass])) {
            $possibleNodes = $this->eventNodeMap[$eventClass];

            if (count($possibleNodes) > 1) {
                throw new WorkflowException("Multiple nodes found that accept event: {$eventClass}. Each event type should only be handled by one node.");
            }

            return array_keys($possibleNodes)[0];
        }

        // If no exact match, try to find nodes that accept parent classes/interfaces
        foreach ($this->eventNodeMap as $acceptedEventClass => $nodes) {
            if (is_a($event, $acceptedEventClass)) {
                if (count($nodes) > 1) {
                    throw new WorkflowException("Multiple nodes found that accept event: {$eventClass}. Each event type should only be handled by one node.");
                }

                return array_keys($nodes)[0];
            }
        }

        return null;
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
        $this->buildEventNodeMap();
        return $this->exporter->export($this);
    }

    public function setExporter(ExporterInterface $exporter): Workflow
    {
        $this->exporter = $exporter;
        return $this;
    }

    private function buildEventNodeMap(): void
    {
        if (!empty($this->eventNodeMap)) {
            return;
        }

        foreach ($this->getNodes() as $nodeClass => $node) {
            $reflection = new ReflectionClass($node);
            $runMethod = $reflection->getMethod('run');
            $parameters = $runMethod->getParameters();
            $eventParameter = $parameters[0];
            $eventType = $eventParameter->getType();

            var_dump($nodeClass);

            if (!$eventType) {
                throw new WorkflowException("Node {$nodeClass} first parameter must be an ".Event::class." type");
            }

            if ($eventType->isBuiltin()) {
                throw new WorkflowException("Node {$nodeClass} first parameter must be an ".Event::class." type");
            }

            $eventClass = $eventType->getName();

            if (!isset($this->eventNodeMap[$eventClass])) {
                $this->eventNodeMap[$eventClass] = [];
            }

            $this->eventNodeMap[$eventClass][$nodeClass] = $node;
        }
    }

    /**
     * @return array<string, array<string, NodeInterface>>
     * @throws WorkflowException
     */
    public function getEventNodeMap(): array
    {
        $this->buildEventNodeMap();
        return $this->eventNodeMap;
    }
}
