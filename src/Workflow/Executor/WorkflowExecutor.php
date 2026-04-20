<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Executor;

use Generator;
use Inspector\Exceptions\InspectorException;
use NeuronAI\Exceptions\WorkflowException;
use NeuronAI\Observability\EventBus;
use NeuronAI\Observability\Events\BranchEnd;
use NeuronAI\Observability\Events\BranchStart;
use NeuronAI\Observability\Events\MiddlewareEnd;
use NeuronAI\Observability\Events\MiddlewareStart;
use NeuronAI\Observability\Events\WorkflowNodeEnd;
use NeuronAI\Observability\Events\WorkflowNodeStart;
use NeuronAI\Workflow\Events\Event;
use NeuronAI\Workflow\Events\ParallelEvent;
use NeuronAI\Workflow\Events\StopEvent;
use NeuronAI\Workflow\Interrupt\BranchInterrupt;
use NeuronAI\Workflow\Interrupt\InterruptRequest;
use NeuronAI\Workflow\Interrupt\WorkflowInterrupt;
use NeuronAI\Workflow\NodeInterface;
use NeuronAI\Workflow\Workflow;
use NeuronAI\Workflow\WorkflowInterface;
use NeuronAI\Workflow\WorkflowState;

/**
 * Default executor that processes nodes sequentially.
 */
class WorkflowExecutor implements WorkflowExecutorInterface
{
    /**
     * Execute the workflow starting from the given event and node.
     *
     * Runs nodes sequentially until a StopEvent is reached. Any exception
     * (including WorkflowInterrupt) propagates up to the caller — interrupt
     * persistence and lifecycle events are the caller's responsibility.
     *
     * @return Generator<int, Event, mixed, void>
     * @throws InspectorException
     * @throws WorkflowException
     */
    public function execute(
        WorkflowInterface $workflow,
        Event $currentEvent,
        NodeInterface $currentNode,
        ?InterruptRequest $resumeRequest = null
    ): Generator {
        while (!($currentEvent instanceof StopEvent)) {
            $nodeGenerator = $this->executeNode($workflow, $currentEvent, $currentNode, $resumeRequest);
            yield from $nodeGenerator;
            $currentEvent = $nodeGenerator->getReturn();

            if ($currentEvent instanceof StopEvent) {
                break;
            }

            $currentNode = $workflow->getNodeForEvent($currentEvent::class);
            $resumeRequest = null;
        }
    }

    /**
     * Execute a single node, yielding any streamed events and returning the next event.
     *
     * If the node returns a ParallelEvent, branches are executed and the ParallelEvent
     * is returned for standard routing to a join node.
     *
     * @return Generator<int, Event, mixed, Event>
     * @throws WorkflowException|InspectorException
     */
    protected function executeNode(
        WorkflowInterface $workflow,
        Event $currentEvent,
        NodeInterface $currentNode,
        ?InterruptRequest $resumeRequest = null,
        ?WorkflowState $state = null,
        ?string $branchId = null
    ): Generator {
        $state ??= $workflow->resolveState();

        $currentNode->setWorkflowContext(
            $state,
            $currentEvent,
            $resumeRequest
        );

        EventBus::emit(
            'workflow-node-start',
            $workflow,
            new WorkflowNodeStart($currentNode::class, $state),
            $workflow->getWorkflowId(),
            $branchId
        );

        try {
            $this->runBeforeMiddleware($workflow, $currentEvent, $currentNode, $state, $branchId);

            $result = $currentNode->run($currentEvent, $state);

            if ($result instanceof Generator) {
                foreach ($result as $event) {
                    yield $event;
                }
                $nodeResult = $result->getReturn();
            } else {
                $nodeResult = $result;
            }

            if ($nodeResult instanceof ParallelEvent) {
                $parallelGen = $this->executeParallelBranches($workflow, $nodeResult);
                yield from $parallelGen;
                $nodeResult = $parallelGen->getReturn();
            }

            $this->runAfterMiddleware($workflow, $nodeResult, $currentNode, $state, $branchId);
        } finally {
            EventBus::emit(
                'workflow-node-end',
                $workflow,
                new WorkflowNodeEnd($currentNode::class, $state),
                $workflow->getWorkflowId(),
                $branchId
            );
        }

        return $nodeResult;
    }

    /**
     * Execute parallel branches sequentially, one after the other.
     *
     * After all branches are complete, each branch's result (from StopEvent::getResult())
     * is stored in {@see ParallelEvent::$results}. The ParallelEvent is then
     * returned for normal routing to a join node.
     *
     * Subclasses can override this method to change how branches run
     * (e.g. AsyncExecutor runs branches concurrently via Amp futures).
     *
     * @return Generator<int, Event, mixed, Event>
     *
     * @throws WorkflowException|InspectorException
     */
    protected function executeParallelBranches(
        WorkflowInterface $workflow,
        ParallelEvent $parallelEvent,
        ?WorkflowInterrupt $interrupt = null,
        ?InterruptRequest $resumeRequest = null,
    ): Generator {
        foreach ($parallelEvent->branches as $branchId => $branchEvent) {
            if ($parallelEvent->hasResult($branchId)) {
                continue;
            }

            // When $interrupt is non-null and its branch matches, $isResuming is true
            // and $interrupt is guaranteed non-null for the rest of this iteration.
            $isResuming = ($branchId === $interrupt?->getBranchId());

            try {
                $result = $this->executeBranch(
                    $workflow,
                    $branchId,
                    $isResuming ? $interrupt->getEvent() : $branchEvent,
                    $isResuming ? $resumeRequest : null,
                    $isResuming ? $interrupt->getNode() : null,
                );

                $parallelEvent->setResult($branchId, $result->result);

                foreach ($result->streamedEvents as $streamedEvent) {
                    yield $streamedEvent;
                }
            } catch (BranchInterrupt $branchInterrupt) {
                throw new WorkflowInterrupt(
                    request: $branchInterrupt->original->getRequest(),
                    node: $branchInterrupt->original->getNode(),
                    state: $workflow->resolveState(),
                    event: $branchInterrupt->original->getEvent(),
                    branchId: $branchInterrupt->branchId,
                    parallelEvent: $parallelEvent,
                    completedBranchResults: $parallelEvent->getAllResults(),
                );
            }
        }

        return $parallelEvent;
    }

    /**
     * Execute a single branch in isolation with a cloned state.
     *
     * Runs the branch's node graph from branchEvent until StopEvent, capturing
     * the StopEvent's result and any streamed events. Shared by both sequential
     * and async execution.
     *
     * @throws WorkflowException|InspectorException
     */
    protected function executeBranch(
        WorkflowInterface $workflow,
        string $branchId,
        Event $branchEvent,
        ?InterruptRequest $resumeRequest = null,
        ?NodeInterface $startNode = null,
    ): BranchResult {
        $streamedEvents = [];

        $branchState = clone $workflow->resolveState();
        $branchState->set('__branchId', $branchId);

        EventBus::emit(
            'branch-start',
            $workflow,
            new BranchStart($branchId),
            $workflow->getWorkflowId(),
            $branchId
        );

        try {
            $currentNode = $startNode ?? $workflow->getNodeForEvent($branchEvent::class);
            $currentEvent = $branchEvent;

            while (!($currentEvent instanceof StopEvent)) {
                $nodeGenerator = $this->executeNode($workflow, $currentEvent, $currentNode, $resumeRequest, $branchState, $branchId);
                foreach ($nodeGenerator as $streamedEvent) {
                    $streamedEvents[] = $streamedEvent;
                }
                $currentEvent = $nodeGenerator->getReturn();

                $resumeRequest = null;

                if ($currentEvent instanceof StopEvent) {
                    break;
                }

                $currentNode = $workflow->getNodeForEvent($currentEvent::class);
            }
        } catch (WorkflowInterrupt $interrupt) {
            throw new BranchInterrupt($branchId, $interrupt);
        } finally {
            EventBus::emit(
                'branch-end',
                $workflow,
                new BranchEnd($branchId),
                $workflow->getWorkflowId(),
                $branchId
            );
        }

        return new BranchResult(
            branchId: $branchId,
            result: $currentEvent->getResult(),
            streamedEvents: $streamedEvents,
        );
    }

    /**
     * @throws InspectorException
     */
    protected function runBeforeMiddleware(
        WorkflowInterface $workflow,
        Event $event,
        NodeInterface $node,
        ?WorkflowState $state = null,
        ?string $branchId = null
    ): void {
        $state ??= $workflow->resolveState();
        foreach ($workflow->getMiddlewareForNode($node) as $m) {
            EventBus::emit('middleware-before-start', $workflow, new MiddlewareStart($m, $event), $workflow->getWorkflowId(), $branchId);
            $m->before($node, $event, $state);
            EventBus::emit('middleware-before-end', $workflow, new MiddlewareEnd($m), $workflow->getWorkflowId(), $branchId);
        }
    }

    /**
     * @throws InspectorException
     */
    protected function runAfterMiddleware(
        WorkflowInterface $workflow,
        Event $event,
        NodeInterface $node,
        ?WorkflowState $state = null,
        ?string $branchId = null
    ): void {
        $state ??= $workflow->resolveState();
        foreach ($workflow->getMiddlewareForNode($node) as $m) {
            EventBus::emit('middleware-after-start', $workflow, new MiddlewareStart($m, $event), $workflow->getWorkflowId(), $branchId);
            $m->after($node, $event, $state);
            EventBus::emit('middleware-after-end', $workflow, new MiddlewareEnd($m), $workflow->getWorkflowId(), $branchId);
        }
    }

    /**
     * Resume the workflow from a persisted interrupt.
     *
     * Handles both linear and parallel interrupts: for parallel interrupts
     * it resumes branches first, then continues linear execution to the join node.
     *
     * @return Generator<int, Event, mixed, void>
     * @throws WorkflowException|InspectorException
     */
    public function resume(
        WorkflowInterface $workflow,
        WorkflowInterrupt $interrupt,
        InterruptRequest $resumeRequest
    ): Generator {
        if ($interrupt->isParallelInterrupt()) {
            $gen = $this->executeParallelBranches(
                $workflow,
                $interrupt->getParallelEvent(),
                $interrupt,
                $resumeRequest,
            );
            yield from $gen;
            $parallelEvent = $gen->getReturn();

            yield from $this->execute(
                $workflow,
                $parallelEvent,
                $workflow->getNodeForEvent($parallelEvent::class)
            );
        } else {
            yield from $this->execute(
                $workflow,
                $interrupt->getEvent(),
                $interrupt->getNode(),
                $resumeRequest
            );
        }
    }

}
