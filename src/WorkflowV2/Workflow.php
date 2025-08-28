<?php

declare(strict_types=1);

namespace NeuronAI\WorkflowV2;

use NeuronAI\Exceptions\WorkflowException;
use NeuronAI\Observability\Events\AgentError;
use NeuronAI\Observability\Events\WorkflowNodeEnd;
use NeuronAI\Observability\Events\WorkflowNodeStart;
use NeuronAI\Observability\Observable;
use NeuronAI\WorkflowV2\Persistence\InMemoryPersistence;
use NeuronAI\WorkflowV2\Persistence\PersistenceInterface;
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

    protected ?string $startNode = null;

    protected PersistenceInterface $persistence;

    protected string $workflowId;

    public function __construct(?PersistenceInterface $persistence = null, ?string $workflowId = null)
    {
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
        try {
            $this->validate();
        } catch (WorkflowException $exception) {
            $this->notify('error', new AgentError($exception));
            throw $exception;
        }

        $state = $initialState ?? new WorkflowState();
        $currentNode = $this->getStartNode();

        $result = $this->execute($currentNode, $state);

        return $result;
    }

    /**
     * @throws WorkflowInterrupt|WorkflowException|\Throwable
     */
    public function resume(array|string|int $humanFeedback): WorkflowState
    {
        $interrupt = $this->persistence->load($this->workflowId);

        $state = $interrupt->getState();
        $currentNode = $interrupt->getCurrentNode();

        $result = $this->execute(
            $currentNode,
            $state,
            true,
            $humanFeedback
        );

        return  $result;
    }

    /**
     * @return Node[]
     */
    protected function nodes(): array
    {
        return [];
    }

    public function addNode(NodeInterface $node): self
    {
        $this->nodes[$node::class] = $node;
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
}
