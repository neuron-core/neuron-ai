<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Executor;

use Generator;
use Inspector\Exceptions\InspectorException;
use NeuronAI\Exceptions\WorkflowException;
use NeuronAI\Observability\EventBus;
use NeuronAI\Observability\Events\MiddlewareEnd;
use NeuronAI\Observability\Events\MiddlewareStart;
use NeuronAI\Observability\Events\WorkflowNodeEnd;
use NeuronAI\Observability\Events\WorkflowNodeStart;
use NeuronAI\Workflow\Events\Event;
use NeuronAI\Workflow\Events\ParallelEvent;
use NeuronAI\Workflow\Events\StopEvent;
use NeuronAI\Workflow\Interrupt\InterruptRequest;
use NeuronAI\Workflow\NodeInterface;
use NeuronAI\Workflow\Workflow;
use NeuronAI\Workflow\WorkflowState;
use Throwable;

use function array_diff_assoc;
use function sprintf;

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
        Workflow $workflow,
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

            $nextEventClass = $currentEvent::class;
            if (!$workflow->hasNodeForEvent($nextEventClass)) {
                throw new WorkflowException(
                    "No node found that handle event: " . $nextEventClass
                );
            }

            $currentNode = $workflow->getNodeForEvent($nextEventClass);
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
     * @throws InspectorException
     */
    protected function executeNode(
        Workflow $workflow,
        Event $currentEvent,
        NodeInterface $currentNode,
        ?InterruptRequest $resumeRequest = null,
        ?WorkflowState $state = null
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
            $workflow->getWorkflowId()
        );

        try {
            $this->runBeforeMiddleware($workflow, $currentEvent, $currentNode, $state);

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

            $this->runAfterMiddleware($workflow, $nodeResult, $currentNode, $state);
        } finally {
            EventBus::emit(
                'workflow-node-end',
                $workflow,
                new WorkflowNodeEnd($currentNode::class, $state),
                $workflow->getWorkflowId()
            );
        }

        return $nodeResult;
    }

    /**
     * Execute parallel branches sequentially, one after the other.
     *
     * After all branches complete, branch state changes are stored under
     * "branches.{branchId}.*" in WorkflowState and the ParallelEvent is returned
     * for normal routing. Register a join node that handles the ParallelEvent subclass
     * to continue the workflow and read branch results from state.
     *
     * Subclasses can override this method to change how branches run
     * (e.g. AsyncExecutor runs branches concurrently via Amp futures).
     *
     * @return Generator<int, Event, mixed, Event>
     */
    protected function executeParallelBranches(
        Workflow $workflow,
        ParallelEvent $parallelEvent
    ): Generator {
        foreach ($parallelEvent->branches as $branchId => $branchEvent) {
            $result = $this->executeBranch($workflow, $branchId, $branchEvent);

            foreach ($result->stateChanges as $key => $value) {
                $workflow->resolveState()->set("branches.{$branchId}.{$key}", $value);
            }

            foreach ($result->streamedEvents as $streamedEvent) {
                yield $streamedEvent;
            }
        }

        return $parallelEvent;
    }

    /**
     * Execute a single branch in isolation with a cloned state.
     *
     * Runs the branch's node graph from branchEvent until StopEvent, collecting
     * state changes and streamed events. Shared by both sequential and async execution.
     */
    protected function executeBranch(
        Workflow $workflow,
        string $branchId,
        Event $branchEvent
    ): BranchResult {
        $streamedEvents = [];
        $originalStateData = $workflow->resolveState()->all();

        try {
            $branchState = clone $workflow->resolveState();

            if (!$workflow->hasNodeForEvent($branchEvent::class)) {
                throw new WorkflowException(
                    sprintf("No node found for branch '%s' event: %s", $branchId, $branchEvent::class)
                );
            }

            $currentNode = $workflow->getNodeForEvent($branchEvent::class);
            $currentEvent = $branchEvent;

            while (!($currentEvent instanceof StopEvent)) {
                $nodeGenerator = $this->executeNode($workflow, $currentEvent, $currentNode, null, $branchState);
                foreach ($nodeGenerator as $streamedEvent) {
                    $streamedEvents[] = $streamedEvent;
                }
                $currentEvent = $nodeGenerator->getReturn();

                if ($currentEvent instanceof StopEvent) {
                    break;
                }

                if (!$workflow->hasNodeForEvent($currentEvent::class)) {
                    throw new WorkflowException(
                        sprintf("Branch '%s': No node for event %s", $branchId, $currentEvent::class)
                    );
                }

                $currentNode = $workflow->getNodeForEvent($currentEvent::class);
            }

            return new BranchResult(
                branchId: $branchId,
                finalEvent: $currentEvent,
                stateChanges: array_diff_assoc($branchState->all(), $originalStateData),
                streamedEvents: $streamedEvents,
            );
        } catch (Throwable $e) {
            return new BranchResult(
                branchId: $branchId,
                finalEvent: $currentEvent ?? $branchEvent,
                streamedEvents: $streamedEvents,
                error: $e,
            );
        }
    }

    protected function runBeforeMiddleware(
        Workflow $workflow,
        Event $event,
        NodeInterface $node,
        ?WorkflowState $state = null
    ): void {
        $state ??= $workflow->resolveState();
        foreach ($workflow->getMiddlewareForNode($node) as $m) {
            EventBus::emit('middleware-before-start', $workflow, new MiddlewareStart($m, $event), $workflow->getWorkflowId());
            $m->before($node, $event, $state);
            EventBus::emit('middleware-before-end', $workflow, new MiddlewareEnd($m), $workflow->getWorkflowId());
        }
    }

    protected function runAfterMiddleware(
        Workflow $workflow,
        Event $event,
        NodeInterface $node,
        ?WorkflowState $state = null
    ): void {
        $state ??= $workflow->resolveState();
        foreach ($workflow->getMiddlewareForNode($node) as $m) {
            EventBus::emit('middleware-after-start', $workflow, new MiddlewareStart($m, $event), $workflow->getWorkflowId());
            $m->after($node, $event, $state);
            EventBus::emit('middleware-after-end', $workflow, new MiddlewareEnd($m), $workflow->getWorkflowId());
        }
    }
}
