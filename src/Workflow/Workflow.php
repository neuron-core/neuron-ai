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

    /**
     * @var NodeInterface[]
     */
    protected array $nodes = [];

    /**
     * @var Edge[]
     */
    protected array $edges = [];

    protected ?string $startNode = null;

    protected ?array $endNodes = null;

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

    public function validate(): void
    {
        /*if ($this->getStartNode() === null) {
            throw new WorkflowException('Start node must be defined');
        }

        if ($this->getEndNode() === null) {
            throw new WorkflowException('End node must be defined');
        }*/

        if (!isset($this->getNodes()[$this->getStartNode()])) {
            throw new WorkflowException("Start node {$this->getStartNode()} does not exist");
        }

        foreach ($this->getEndNodes() as $endNode) {
            if (!isset($this->getNodes()[$endNode])) {
                throw new WorkflowException("End node {$endNode} does not exist");
            }
        }

        foreach ($this->getEdges() as $edge) {
            if (!isset($this->getNodes()[$edge->getFrom()])) {
                throw new WorkflowException("Edge from node {$edge->getFrom()} does not exist");
            }

            if (!isset($this->getNodes()[$edge->getTo()])) {
                throw new WorkflowException("Edge to node {$edge->getTo()} does not exist");
            }
        }
    }

    /**
     * @throws WorkflowInterrupt|WorkflowException|\Throwable
     */
    protected function execute(
        string $currentNode,
        WorkflowState $state,
        bool $resuming = false,
        array|string|int $humanFeedback = []
    ): WorkflowState {
        $context = new WorkflowContext(
            $this->workflowId,
            $currentNode,
            $this->persistence,
            $state
        );

        if ($resuming) {
            $context->setResuming(true, [$currentNode => $humanFeedback]);
        }

        try {
            while (!\in_array($currentNode, $this->getEndNodes())) {
                $node = $this->nodes[$currentNode];
                $node->setContext($context);

                $this->notify('workflow-node-start', new WorkflowNodeStart($currentNode, $state));
                try {
                    $state = $node->run($state);
                } catch (WorkflowInterrupt $interrupt) {
                    throw $interrupt;
                } catch (\Throwable $exception) {
                    $this->notify('error', new AgentError($exception));
                    throw $exception;
                }
                $this->notify('workflow-node-end', new WorkflowNodeEnd($currentNode, $state));

                $nextNode = $this->findNextNode($currentNode, $state);

                if ($nextNode === null) {
                    throw new WorkflowException("No valid edge found from node {$currentNode}");
                }

                $currentNode = $nextNode;

                // Update the context before the next iteration or end node
                $context = new WorkflowContext(
                    $this->workflowId,
                    $currentNode,
                    $this->persistence,
                    $state
                );
            }

            $endNode = $this->nodes[$currentNode];
            $endNode->setContext($context);
            $result = $endNode->run($state);
            $this->persistence->delete($this->workflowId);
            return $result;

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
        $this->notify('workflow-start', new WorkflowStart($this->getNodes(), $this->getEdges()));
        try {
            $this->validate();
        } catch (WorkflowException $exception) {
            $this->notify('error', new AgentError($exception));
            throw $exception;
        }

        $state = $initialState ?? new WorkflowState();
        $currentNode = $this->getStartNode();

        $result = $this->execute($currentNode, $state);
        $this->notify('workflow-end', new WorkflowEnd($result));

        return $result;
    }

    /**
     * @throws WorkflowInterrupt|WorkflowException|\Throwable
     */
    public function resume(array|string|int $humanFeedback): WorkflowState
    {
        $this->notify('workflow-resume', new WorkflowStart($this->getNodes(), $this->getEdges()));
        $interrupt = $this->persistence->load($this->workflowId);

        $state = $interrupt->getState();
        $currentNode = $interrupt->getCurrentNode();

        $result = $this->execute(
            $currentNode,
            $state,
            true,
            $humanFeedback
        );
        $this->notify('workflow-end', new WorkflowEnd($result));

        return  $result;
    }

    /**
     * @return Node[]
     */
    protected function nodes(): array
    {
        return [];
    }

    /**
     * @return Edge[]
     */
    protected function edges(): array
    {
        return [];
    }

    public function addNode(NodeInterface $node, ?string $key = null): self
    {
        $nodeKey = $key ?? $node::class;
        $this->nodes[$nodeKey] = $node;
        return $this;
    }

    /**
     * @param NodeInterface[]|array<string, NodeInterface> $nodes
     */
    public function addNodes(array $nodes): Workflow
    {
        // Check if it's an associative array
        $isAssociative = \count(\array_filter(\array_keys($nodes), 'is_string')) > 0;

        if ($isAssociative) {
            // If associative, use the keys
            foreach ($nodes as $key => $node) {
                $this->addNode($node, $key);
            }
        } else {
            // If indexed, use class names as keys
            foreach ($nodes as $node) {
                $this->addNode($node);
            }
        }
        return $this;
    }

    /**
     * @return array<string, NodeInterface>
     */
    public function getNodes(): array
    {
        if ($this->nodes === []) {
            $nodeDefinitions = $this->nodes();

            // Check if it's an associative array (has string keys)
            // An associative array has string keys or non-sequential numeric keys
            $isAssociative = \count(\array_filter(\array_keys($nodeDefinitions), 'is_string')) > 0;

            if ($isAssociative) {
                // New behavior: use provided keys directly
                $this->nodes = $nodeDefinitions;
            } else {
                // Old behavior: use class names as keys
                foreach ($nodeDefinitions as $node) {
                    $this->addNode($node);
                }
            }
        }

        return $this->nodes;
    }

    public function addEdge(Edge $edge): self
    {
        $this->edges[] = $edge;
        return $this;
    }

    /**
     * @param Edge[] $edges
     */
    public function addEdges(array $edges): Workflow
    {
        foreach ($edges as $edge) {
            $this->addEdge($edge);
        }
        return $this;
    }

    /**
     * @return Edge[]
     */
    public function getEdges(): array
    {
        if ($this->edges === []) {
            $this->edges = $this->edges();
        }

        return $this->edges;
    }

    public function setStart(string $nodeClass): Workflow
    {
        $this->startNode = $nodeClass;
        return $this;
    }

    public function setEnd(string $nodeClass): Workflow
    {
        $this->endNodes[] = $nodeClass;
        return $this;
    }

    protected function getStartNode(): string
    {
        return $this->startNode ?? $this->start();
    }

    protected function getEndNodes(): array
    {
        return $this->endNodes ?? $this->end();
    }

    protected function start(): ?string
    {
        throw new WorkflowException('Start node must be defined');
    }

    protected function end(): array
    {
        throw new WorkflowException('End node must be defined');
    }

    private function findNextNode(string $currentNode, WorkflowState $state): ?string
    {
        foreach ($this->getEdges() as $edge) {
            if ($edge->getFrom() === $currentNode && $edge->shouldExecute($state)) {
                return $edge->getTo();
            }
        }

        return null;
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
}
