<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Execution;

use Generator;
use NeuronAI\Exceptions\WorkflowException;
use NeuronAI\Observability\EventBus;
use NeuronAI\Workflow\Events\Event;
use NeuronAI\Workflow\Events\ForkEvent;
use NeuronAI\Workflow\Events\JoinEvent;
use NeuronAI\Workflow\Events\StopEvent;
use NeuronAI\Workflow\Interrupt\InterruptRequest;
use NeuronAI\Workflow\Node\ParallelNode;
use NeuronAI\Workflow\NodeInterface;
use NeuronAI\Workflow\Workflow;
use Throwable;

use function Amp\async;
use function Amp\Future\await;
use function array_diff_assoc;
use function array_keys;

/**
 * Async-aware executor that runs ParallelNode branches concurrently using Amp.
 *
 * Extends SequentialExecutor and overrides executeNode() to intercept ParallelNode
 * instances and run their branches concurrently instead of sequentially.
 */
class AsyncExecutor extends SequentialExecutor
{
    /**
     * Override to route ParallelNode instances to concurrent branch execution.
     *
     * @return Generator<int, Event, mixed, Event>
     */
    protected function executeNode(
        Workflow $workflow,
        Event $currentEvent,
        NodeInterface $currentNode,
        ?InterruptRequest $resumeRequest = null
    ): Generator {
        if ($currentNode instanceof ParallelNode) {
            return $this->executeParallel($workflow, $currentNode, $currentEvent);
        }

        return parent::executeNode($workflow, $currentEvent, $currentNode, $resumeRequest);
    }

    /**
     * Execute parallel branches concurrently.
     *
     * @return Generator<int, Event, mixed, Event>
     */
    protected function executeParallel(
        Workflow $workflow,
        ParallelNode $node,
        Event $event
    ): Generator {
        $branches = $node->branches($event, $workflow->resolveState());

        if ($branches === []) {
            return $node->merge([], $workflow->resolveState());
        }

        EventBus::emit(
            'workflow-parallel-start',
            $workflow,
            ['node' => $node::class, 'branches' => array_keys($branches)],
            $workflow->getWorkflowId()
        );

        yield new ForkEvent($branches);

        $futures = [];
        foreach ($branches as $branchId => $branchEvent) {
            $futures[$branchId] = async(
                fn (): \NeuronAI\Workflow\Execution\BranchResult => $this->executeBranch($workflow, $branchId, $branchEvent, $node)
            );
        }

        /** @var array<string, BranchResult> $branchResults */
        $branchResults = await($futures);

        $mergedResults = [];

        foreach ($branchResults as $branchId => $result) {
            if ($result->hasError()) {
                if (!$node->onBranchError($branchId, $result->error)) {
                    throw $result->error;
                }
            } else {
                $mergedResults[$branchId] = $result->finalEvent;
                foreach ($result->stateChanges as $key => $value) {
                    $workflow->resolveState()->set("branches.{$branchId}.{$key}", $value);
                }
            }

            foreach ($result->streamedEvents as $streamedEvent) {
                yield $streamedEvent;
            }
        }

        $nextEvent = $node->merge($mergedResults, $workflow->resolveState());

        yield new JoinEvent($mergedResults);

        EventBus::emit(
            'workflow-parallel-end',
            $workflow,
            ['node' => $node::class, 'branches' => array_keys($branches)],
            $workflow->getWorkflowId()
        );

        return $nextEvent;
    }

    /**
     * Execute a single branch in isolation with a cloned state.
     */
    protected function executeBranch(
        Workflow $workflow,
        string $branchId,
        Event $branchEvent,
        ParallelNode $parentNode
    ): BranchResult {
        $streamedEvents = [];
        $originalState = $workflow->resolveState();
        $originalStateData = $originalState->all();

        try {
            $branchState = clone $originalState;

            $branchNodeClass = $branchEvent::class;
            if (!$workflow->hasNodeForEvent($branchNodeClass)) {
                throw new WorkflowException(
                    "No node found for branch '{$branchId}' event: {$branchNodeClass}"
                );
            }

            $currentNode = $workflow->getNodeForEvent($branchNodeClass);
            $currentEvent = $branchEvent;

            while (!($currentEvent instanceof StopEvent)) {
                $currentNode->setWorkflowContext($branchState, $currentEvent);

                EventBus::emit(
                    'workflow-branch-node-start',
                    $workflow,
                    ['branchId' => $branchId, 'node' => $currentNode::class],
                    $workflow->getWorkflowId()
                );

                $this->runBeforeMiddleware($workflow, $currentEvent, $currentNode, $branchState);
                $result = $currentNode->run($currentEvent, $branchState);

                if ($result instanceof Generator) {
                    foreach ($result as $event) {
                        $streamedEvents[] = $event;
                        $parentNode->onBranchEvent($branchId, $event);
                    }
                    $currentEvent = $result->getReturn();
                } else {
                    $currentEvent = $result;
                }

                $this->runAfterMiddleware($workflow, $currentEvent, $currentNode, $branchState);

                EventBus::emit(
                    'workflow-branch-node-end',
                    $workflow,
                    ['branchId' => $branchId, 'node' => $currentNode::class],
                    $workflow->getWorkflowId()
                );

                if ($currentEvent instanceof StopEvent) {
                    break;
                }

                // Handle nested ParallelNode
                if ($currentNode instanceof ParallelNode) {
                    $nestedResult = $this->executeParallel($workflow, $currentNode, $currentEvent);

                    foreach ($nestedResult as $event) {
                        $streamedEvents[] = $event;
                        $parentNode->onBranchEvent($branchId, $event);
                    }

                    $currentEvent = $nestedResult->getReturn();

                    if ($currentEvent instanceof StopEvent) {
                        break;
                    }
                }

                $nextEventClass = $currentEvent::class;
                if (!$workflow->hasNodeForEvent($nextEventClass)) {
                    throw new WorkflowException(
                        "Branch '{$branchId}': No node for event {$nextEventClass}"
                    );
                }

                $currentNode = $workflow->getNodeForEvent($nextEventClass);
            }

            $stateChanges = array_diff_assoc($branchState->all(), $originalStateData);

            return new BranchResult(
                branchId: $branchId,
                finalEvent: $currentEvent,
                stateChanges: $stateChanges,
                streamedEvents: $streamedEvents
            );
        } catch (Throwable $e) {
            return new BranchResult(
                branchId: $branchId,
                finalEvent: $currentEvent ?? $branchEvent,
                streamedEvents: $streamedEvents,
                error: $e
            );
        }
    }
}
