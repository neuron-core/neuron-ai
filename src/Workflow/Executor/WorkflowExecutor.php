<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Executor;

use Generator;
use Inspector\Exceptions\InspectorException;
use NeuronAI\Observability\EventBus;
use NeuronAI\Observability\Events\AgentError;
use NeuronAI\Observability\Events\BranchEnd;
use NeuronAI\Observability\Events\BranchStart;
use NeuronAI\Observability\Events\WorkflowEnd;
use NeuronAI\Observability\Events\WorkflowStart;
use NeuronAI\Workflow\Events\Event;
use NeuronAI\Workflow\Events\ParallelEvent;
use NeuronAI\Workflow\Events\StopEvent;
use NeuronAI\Workflow\Interrupt\BranchInterrupt;
use NeuronAI\Workflow\Interrupt\InterruptRequest;
use NeuronAI\Workflow\Interrupt\WorkflowInterrupt;
use NeuronAI\Workflow\NodeInterface;
use NeuronAI\Workflow\WorkflowInterface;
use NeuronAI\Workflow\WorkflowState;
use Throwable;

/**
 * Durable workflow executor with replay-based traversal.
 *
 * Every node executes as a memoized step via a StepEngine.
 * Completed steps skip on replay. Interrupted steps resume
 * from the stored InterruptRequest. Crash recovery and
 * interrupt resume share the same replay path.
 */
class WorkflowExecutor implements WorkflowExecutorInterface
{
    public function __construct(
        protected StepEngine $stepEngine = new LocalStepEngine(),
        protected NodeRunner $nodeRunner = new DefaultNodeRunner(),
    ) {
    }

    /**
     * @return Generator<int, Event, mixed, WorkflowState>
     * @throws Throwable
     * @throws WorkflowInterrupt
     */
    public function execute(
        WorkflowInterface $workflow,
        ?InterruptRequest $interrupt = null,
    ): Generator {
        $workflow->bootstrap();

        $workflowId = $workflow->getWorkflowId();
        EventBus::emit('workflow-start', $workflow, new WorkflowStart($workflow->getEventNodeMap()), $workflowId);
        $workflow->resolveState()->set('__workflowId', $workflowId);

        try {
            $this->stepEngine->prepareExecution($interrupt);

            yield from $this->traverse(
                $workflow,
                $workflow->getStartEvent(),
                $workflow->getNodeForEvent($workflow->getStartEvent()::class),
            );

            $this->stepEngine->deleteSteps();
        } catch (WorkflowInterrupt $interrupt) {
            EventBus::emit('error', $workflow, new AgentError($interrupt, false), $workflowId);
            throw $interrupt;
        } catch (Throwable $exception) {
            // Don't emit error for platform step-pending (expected control flow)
            if ($exception::class !== \Deeplinq\StepPendingException::class) {
                EventBus::emit('error', $workflow, new AgentError($exception), $workflowId);
            }
            throw $exception;
        } finally {
            $this->workflowEnd($workflow);
        }

        return $workflow->resolveState();
    }

    /**
     * Build a unique step identifier for memoization.
     */
    protected function buildStepId(NodeInterface $node, ?string $branchId, int $index): string
    {
        $base = $branchId !== null
            ? $branchId . '.' . $node::class
            : $node::class;

        return $base . '-' . $index;
    }

    /**
     * Traverse nodes sequentially from the given starting point.
     *
     * @return Generator<int, Event, mixed, void>
     * @throws WorkflowInterrupt
     * @throws InspectorException
     */
    protected function traverse(
        WorkflowInterface $workflow,
        Event $event,
        NodeInterface $node,
    ): Generator {
        $index = 0;

        while (!($event instanceof StopEvent)) {
            $stepId = $this->buildStepId($node, null, $index++);
            $streamedEvents = [];

            $result = $this->stepEngine->runStep($stepId, function (?InterruptRequest $resumeRequest) use (
                $node,
                $event,
                $workflow,
                &$streamedEvents,
            ): StepResult {
                $state = $workflow->resolveState();
                $middleware = $workflow->getMiddlewareForNode($node);
                $nodeGen = $this->nodeRunner->run($node, $event, $state, $middleware, null, $resumeRequest);
                foreach ($nodeGen as $streamedEvent) {
                    $streamedEvents[] = $streamedEvent;
                }
                return new StepResult(
                    stepId: $node::class,
                    event: $nodeGen->getReturn(),
                    state: $state,
                );
            });

            foreach ($streamedEvents as $streamedEvent) {
                yield $streamedEvent;
            }

            $event = $result->getEvent();
            if ($result->getState() instanceof WorkflowState) {
                $workflow->setState($result->getState());
            }

            if ($event instanceof ParallelEvent) {
                $branchGen = $this->executeBranches($workflow, $event);
                yield from $branchGen;
                $event = $branchGen->getReturn();
            }

            if ($event instanceof StopEvent) {
                break;
            }

            $node = $workflow->getNodeForEvent($event::class);
        }
    }

    /**
     * Execute parallel branches sequentially.
     *
     * Subclasses override this to change branch execution strategy.
     *
     * @return Generator<int, Event, mixed, ParallelEvent>
     * @throws WorkflowInterrupt
     * @throws InspectorException
     */
    protected function executeBranches(
        WorkflowInterface $workflow,
        ParallelEvent $parallelEvent,
        ?WorkflowInterrupt $interrupt = null,
        ?InterruptRequest $resumeRequest = null,
    ): Generator {
        foreach ($parallelEvent->branches as $branchId => $branchEvent) {
            if ($parallelEvent->hasResult($branchId)) {
                continue;
            }

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
     * @throws InspectorException
     * @throws BranchInterrupt
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

        $workflowId = $workflow->getWorkflowId();
        EventBus::emit('branch-start', $workflow, new BranchStart($branchId), $workflowId, $branchId);

        try {
            $node = $startNode ?? $workflow->getNodeForEvent($branchEvent::class);
            $event = $branchEvent;
            $index = 0;

            while (!($event instanceof StopEvent)) {
                $stepId = $this->buildStepId($node, $branchId, $index++);

                $result = $this->stepEngine->runStep($stepId, function (?InterruptRequest $stepResume) use (
                    $node,
                    $event,
                    $branchState,
                    $workflow,
                    $branchId,
                    &$streamedEvents,
                ): StepResult {
                    $middleware = $workflow->getMiddlewareForNode($node);
                    $nodeGen = $this->nodeRunner->run($node, $event, $branchState, $middleware, $branchId, $stepResume);
                    foreach ($nodeGen as $streamedEvent) {
                        $streamedEvents[] = $streamedEvent;
                    }
                    return new StepResult(
                        stepId: $node::class,
                        event: $nodeGen->getReturn(),
                        state: $branchState,
                    );
                });

                $event = $result->getEvent();
                if ($result->getState() instanceof WorkflowState) {
                    $branchState = $result->getState();
                }

                if ($event instanceof ParallelEvent) {
                    $branchGen = $this->executeBranches($workflow, $event);
                    foreach ($branchGen as $streamedEvent) {
                        $streamedEvents[] = $streamedEvent;
                    }
                    $event = $branchGen->getReturn();
                }

                if ($event instanceof StopEvent) {
                    break;
                }

                $node = $workflow->getNodeForEvent($event::class);
            }
        } catch (WorkflowInterrupt $interrupt) {
            throw new BranchInterrupt($branchId, $interrupt);
        } finally {
            EventBus::emit('branch-end', $workflow, new BranchEnd($branchId), $workflowId, $branchId);
        }

        return new BranchResult(
            branchId: $branchId,
            result: $event->getResult(),
            streamedEvents: $streamedEvents,
        );
    }

    protected function workflowEnd(WorkflowInterface $workflow): void
    {
        $workflowId = $workflow->getWorkflowId();
        EventBus::emit('workflow-end', $workflow, new WorkflowEnd($workflow->resolveState()), $workflowId);
        EventBus::clear($workflowId);
    }
}
