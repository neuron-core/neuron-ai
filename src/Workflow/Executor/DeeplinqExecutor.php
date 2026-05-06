<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Executor;

use Deeplinq\Context;
use Deeplinq\StepPendingException;
use NeuronAI\Observability\EventBus;
use NeuronAI\Observability\Events\BranchEnd;
use NeuronAI\Observability\Events\BranchStart;
use NeuronAI\Observability\Events\WorkflowEnd;
use NeuronAI\Observability\Events\WorkflowStart;
use NeuronAI\Workflow\Events\Event;
use NeuronAI\Workflow\Events\ParallelEvent;
use NeuronAI\Workflow\Events\StopEvent;
use NeuronAI\Workflow\WorkflowInterface;
use NeuronAI\Workflow\WorkflowState;

/**
 * Durable workflow executor for Deeplinq.
 *
 * Wraps each node execution in a Deeplinq durable step. The platform
 * invokes the handler repeatedly — each call makes progress through
 * one node, with memoized results preventing re-execution on replay.
 *
 * Uses the same NodeRunner as in-process executors.
 *
 * Usage:
 *   $client = new Deeplinq\Client(...);
 *   $client->register(new Deeplinq\Task(
 *       id: 'my-workflow',
 *       triggers: [...],
 *       handler: new DeeplinqExecutor($workflow),
 *   ));
 */
class DeeplinqExecutor
{
    public function __construct(
        protected WorkflowInterface $workflow,
        protected NodeRunner $nodeRunner = new DefaultNodeRunner(),
    ) {}

    /**
     * Return a Deeplinq-compatible task handler callable.
     */
    public function __invoke(Context $ctx): mixed
    {
        return $this->execute($ctx);
    }

    /**
     * Walk the graph from the first non-memoized step.
     *
     * Completed steps return their memoized result immediately.
     * The first new step executes and throws StepPendingException
     * to yield control back to the platform.
     *
     * @throws StepPendingException
     */
    protected function execute(Context $ctx): WorkflowState
    {
        $workflow = clone $this->workflow;
        $workflow->bootstrap();

        $workflowId = $workflow->getWorkflowId();
        $workflow->resolveState()->set('__workflowId', $workflowId);

        EventBus::emit('workflow-start', $workflow, new WorkflowStart($workflow->getEventNodeMap()), $workflowId);

        $event = $workflow->getStartEvent();
        $node = $workflow->getNodeForEvent($event::class);

        while (!($event instanceof StopEvent)) {
            $event = $this->executeNodeAsStep($workflow, $node, $event, $ctx);

            if ($event instanceof ParallelEvent) {
                $event = $this->executeBranches($workflow, $event, $ctx);
            }

            if ($event instanceof StopEvent) {
                break;
            }

            $node = $workflow->getNodeForEvent($event::class);
        }

        EventBus::emit('workflow-end', $workflow, new WorkflowEnd($workflow->resolveState()), $workflowId);
        EventBus::clear($workflowId);

        return $workflow->resolveState();
    }

    /**
     * Execute a single node as a durable step.
     *
     * Step::run() either returns the memoized Event (replay)
     * or throws StepPendingException after recording the result (first run).
     *
     * @throws StepPendingException
     */
    protected function executeNodeAsStep(
        WorkflowInterface $workflow,
        \NeuronAI\Workflow\NodeInterface $node,
        Event $event,
        Context $ctx,
        ?WorkflowState $state = null,
        ?string $branchId = null,
    ): Event {
        $state ??= $workflow->resolveState();
        $middleware = $workflow->getMiddlewareForNode($node);
        $workflowId = $workflow->getWorkflowId();

        $stepId = $branchId !== null
            ? $branchId . '.' . $node::class
            : $node::class;

        return $ctx->step->run(
            id: $stepId,
            fn: fn (): mixed => $this->nodeRunner->run(
                $node,
                $event,
                $state,
                $middleware,
                $branchId,
            ),
        );
    }

    /**
     * Execute parallel branches, each node as a durable step.
     *
     * @throws StepPendingException
     */
    protected function executeBranches(
        WorkflowInterface $workflow,
        ParallelEvent $parallelEvent,
        Context $ctx,
    ): ParallelEvent {
        foreach ($parallelEvent->branches as $branchId => $branchEvent) {
            if ($parallelEvent->hasResult($branchId)) {
                continue;
            }

            $result = $this->executeBranchGraph(
                $workflow,
                $branchEvent,
                $branchId,
                $ctx,
            );

            $parallelEvent->setResult($branchId, $result);
        }

        return $parallelEvent;
    }

    /**
     * Execute a branch's full node chain.
     *
     * Each node in the chain is a separate durable step.
     * Returns StopEvent::getResult() when the chain completes.
     *
     * @throws StepPendingException
     */
    protected function executeBranchGraph(
        WorkflowInterface $workflow,
        Event $branchEvent,
        string $branchId,
        Context $ctx,
    ): mixed {
        $branchState = clone $workflow->resolveState();
        $branchState->set('__branchId', $branchId);

        $workflowId = $workflow->getWorkflowId();
        EventBus::emit('branch-start', $workflow, new BranchStart($branchId), $workflowId, $branchId);

        try {
            $node = $workflow->getNodeForEvent($branchEvent::class);
            $event = $branchEvent;

            while (!($event instanceof StopEvent)) {
                $event = $this->executeNodeAsStep(
                    $workflow,
                    $node,
                    $event,
                    $ctx,
                    $branchState,
                    $branchId,
                );

                if ($event instanceof StopEvent) {
                    break;
                }

                $node = $workflow->getNodeForEvent($event::class);
            }
        } finally {
            EventBus::emit('branch-end', $workflow, new BranchEnd($branchId), $workflowId, $branchId);
        }

        return $event->getResult();
    }
}
