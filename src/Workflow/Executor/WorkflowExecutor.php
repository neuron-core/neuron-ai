<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Executor;

use Generator;
use Inspector\Exceptions\InspectorException;
use NeuronAI\Exceptions\WorkflowException;
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
use NeuronAI\Workflow\Persistence\InMemoryPersistence;
use NeuronAI\Workflow\Persistence\PersistenceInterface;
use NeuronAI\Workflow\WorkflowInterface;
use NeuronAI\Workflow\WorkflowState;
use Throwable;

/**
 * Traverses the workflow graph one node at a time. ParallelEvent branches execute sequentially.
 *
 * Supports interrupt/resume via an optional PersistenceInterface.
 */
class WorkflowExecutor implements WorkflowExecutorInterface
{
    public function __construct(
        protected ?PersistenceInterface $persistence = new InMemoryPersistence(),
        protected NodeRunner $nodeRunner = new DefaultNodeRunner(),
    ) {}

    /**
     * @return Generator<int, Event, mixed, WorkflowState>
     * @throws Throwable
     * @throws WorkflowException
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
            if ($interrupt instanceof InterruptRequest) {
                yield from $this->executeResume($workflow, $interrupt);
            } else {
                yield from $this->traverse(
                    $workflow,
                    $workflow->getStartEvent(),
                    $workflow->getNodeForEvent($workflow->getStartEvent()::class),
                );
            }

            $this->persistence?->delete($workflowId);
        } catch (WorkflowInterrupt $interrupt) {
            $this->persistence?->save($workflowId, $interrupt);
            EventBus::emit('error', $workflow, new AgentError($interrupt, false), $workflowId);
            throw $interrupt;
        } catch (Throwable $exception) {
            EventBus::emit('error', $workflow, new AgentError($exception), $workflowId);
            throw $exception;
        } finally {
            $this->workflowEnd($workflow);
        }

        return $workflow->resolveState();
    }

    /**
     * Resume execution from a persisted interrupt.
     *
     * @return Generator<int, Event, mixed, void>
     * @throws WorkflowInterrupt
     * @throws InspectorException
     * @throws WorkflowException
     */
    protected function executeResume(WorkflowInterface $workflow, InterruptRequest $userDecision): Generator
    {
        $workflowId = $workflow->getWorkflowId();
        $interrupt = $this->persistence?->load($workflowId)
            ?? throw new WorkflowException('Persistence is required to resume from an interrupt');

        $workflow->setState($interrupt->getState());

        EventBus::emit('workflow-resume', $workflow, new WorkflowStart($workflow->getEventNodeMap()), $workflowId);
        $workflow->resolveState()->set('__workflowId', $workflowId);

        if ($interrupt->isParallelInterrupt()) {
            $branchGen = $this->executeBranches(
                $workflow,
                $interrupt->getParallelEvent(),
                $interrupt,
                $userDecision,
            );
            yield from $branchGen;
            $parallelEvent = $branchGen->getReturn();

            yield from $this->traverse(
                $workflow,
                $parallelEvent,
                $workflow->getNodeForEvent($parallelEvent::class),
            );
        } else {
            yield from $this->traverse(
                $workflow,
                $interrupt->getEvent(),
                $interrupt->getNode(),
                $userDecision,
            );
        }
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
        ?InterruptRequest $resumeRequest = null,
    ): Generator {
        while (!($event instanceof StopEvent)) {
            $middleware = $workflow->getMiddlewareForNode($node);

            $nodeGen = $this->nodeRunner->run(
                $node, $event, $workflow->resolveState(),
                $middleware, null, $resumeRequest,
            );
            yield from $nodeGen;
            $event = $nodeGen->getReturn();

            if ($event instanceof ParallelEvent) {
                $branchGen = $this->executeBranches($workflow, $event);
                yield from $branchGen;
                $event = $branchGen->getReturn();
            }

            if ($event instanceof StopEvent) {
                break;
            }

            $node = $workflow->getNodeForEvent($event::class);
            $resumeRequest = null;
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

            while (!($event instanceof StopEvent)) {
                $middleware = $workflow->getMiddlewareForNode($node);

                $nodeGen = $this->nodeRunner->run(
                    $node, $event, $branchState,
                    $middleware, $branchId, $resumeRequest,
                );
                foreach ($nodeGen as $streamedEvent) {
                    $streamedEvents[] = $streamedEvent;
                }
                $event = $nodeGen->getReturn();

                if ($event instanceof ParallelEvent) {
                    $branchGen = $this->executeBranches($workflow, $event);
                    foreach ($branchGen as $streamedEvent) {
                        $streamedEvents[] = $streamedEvent;
                    }
                    $event = $branchGen->getReturn();
                }

                $resumeRequest = null;

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
