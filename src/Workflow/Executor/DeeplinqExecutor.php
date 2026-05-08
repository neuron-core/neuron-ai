<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Executor;

use Deeplinq\Client;
use Deeplinq\Context;
use Deeplinq\Event as DeeplinqEvent;
use Deeplinq\StepPendingException;
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

use function base64_decode;
use function base64_encode;
use function serialize;
use function unserialize;

/**
 * Standalone durable workflow executor for the Deeplinq platform.
 *
 * Does NOT extend WorkflowExecutor or use the StepEngine layer.
 * Each node executes as a Deeplinq durable step via ctx->step->run().
 * State is packed into step results and restored on replay.
 *
 * Interrupts use a 3-step pattern:
 *   1. Execute node (catch WorkflowInterrupt → return marker)
 *   2. waitForEvent → platform waits for user response
 *   3. Re-execute node with resume request
 *
 * Usage:
 *   $client->register(new Task(
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
    ) {
    }

    /**
     * Deeplinq task handler entry point.
     */
    public function __invoke(Context $ctx): WorkflowState
    {
        $workflow = clone $this->workflow;
        $workflow->bootstrap();

        $workflowId = $workflow->getWorkflowId();
        EventBus::emit('workflow-start', $workflow, new WorkflowStart($workflow->getEventNodeMap()), $workflowId);
        $workflow->resolveState()->set('__workflowId', $workflowId);

        try {
            $this->traverse($workflow, $ctx);

            $this->workflowEnd($workflow);
            return $workflow->resolveState();
        } catch (StepPendingException $e) {
            throw $e;
        } catch (Throwable $exception) {
            EventBus::emit('error', $workflow, new AgentError($exception), $workflowId);
            throw $exception;
        }
    }

    /**
     * Walk the node graph, executing each node as a durable step.
     *
     * @throws StepPendingException
     */
    protected function traverse(WorkflowInterface $workflow, Context $ctx): void
    {
        $event = $workflow->getStartEvent();
        $node = $workflow->getNodeForEvent($event::class);

        while (!($event instanceof StopEvent)) {
            $stepId = $node::class;
            $state = $workflow->resolveState();

            $packed = $ctx->step->run($stepId, function () use ($node, $event, $state, $workflow): array {
                return $this->executeNode($node, $event, $state, $workflow);
            });

            if ($packed['interrupted'] ?? false) {
                $packed = $this->resumeInterruptedNode($ctx, $workflow, $stepId, $node, $event, $state);
            }

            $event = $this->unpackEvent($packed);
            $state = $this->unpackState($packed);
            $workflow->setState($state);

            if ($event instanceof ParallelEvent) {
                $this->executeBranches($workflow, $ctx, $event);
            }

            if ($event instanceof StopEvent) {
                break;
            }

            $node = $workflow->getNodeForEvent($event::class);
        }
    }

    /**
     * Execute a single node, catching WorkflowInterrupt.
     *
     * Returns a packed result array. If the node interrupts,
     * the array carries an 'interrupted' flag instead of a normal event.
     */
    protected function executeNode(
        NodeInterface $node,
        Event $event,
        WorkflowState $state,
        WorkflowInterface $workflow,
        ?InterruptRequest $resumeRequest = null,
    ): array {
        try {
            $middleware = $workflow->getMiddlewareForNode($node);
            $nodeGen = $this->nodeRunner->run($node, $event, $state, $middleware, null, $resumeRequest);
            foreach ($nodeGen as $_) {}
            $resultEvent = $nodeGen->getReturn();

            return $this->pack($resultEvent, $state);
        } catch (WorkflowInterrupt $interrupt) {
            return [
                'interrupted' => true,
                'state' => base64_encode(serialize($interrupt->getState())),
                'request' => base64_encode(serialize($interrupt->getRequest())),
            ];
        }
    }

    /**
     * Handle the 3-step interrupt pattern (steps 2 and 3).
     *
     * Step 2: waitForEvent to get the user's resume data.
     * Step 3: Re-execute the node with the resume request.
     *
     * @throws StepPendingException
     */
    protected function resumeInterruptedNode(
        Context $ctx,
        WorkflowInterface $workflow,
        string $stepId,
        NodeInterface $node,
        Event $event,
        WorkflowState $state,
    ): array {
        $workflowId = $workflow->getWorkflowId();

        $resumeData = $ctx->step->waitForEvent(
            $stepId . '.interrupt',
            'workflow/interrupt/' . $workflowId,
            '7d',
        );

        $resumeRequest = unserialize(base64_decode($resumeData));

        return $ctx->step->run($stepId . '.resumed', function () use (
            $node,
            $event,
            $state,
            $workflow,
            $resumeRequest,
        ): array {
            return $this->executeNode($node, $event, $state, $workflow, $resumeRequest);
        });
    }

    /**
     * Execute parallel branches sequentially.
     *
     * @throws StepPendingException
     */
    protected function executeBranches(
        WorkflowInterface $workflow,
        Context $ctx,
        ParallelEvent $parallelEvent,
    ): void {
        $workflowId = $workflow->getWorkflowId();

        foreach ($parallelEvent->branches as $branchId => $branchEvent) {
            if ($parallelEvent->hasResult($branchId)) {
                continue;
            }

            EventBus::emit('branch-start', $workflow, new BranchStart($branchId), $workflowId, $branchId);

            try {
                $result = $this->executeBranch($workflow, $ctx, $branchId, $branchEvent);
                $parallelEvent->setResult($branchId, $result);
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
            } finally {
                EventBus::emit('branch-end', $workflow, new BranchEnd($branchId), $workflowId, $branchId);
            }
        }
    }

    /**
     * Execute a single branch in isolation with a cloned state.
     *
     * @throws BranchInterrupt
     * @throws StepPendingException
     */
    protected function executeBranch(
        WorkflowInterface $workflow,
        Context $ctx,
        string $branchId,
        Event $branchEvent,
    ): mixed {
        $branchState = clone $workflow->resolveState();
        $branchState->set('__branchId', $branchId);

        $event = $branchEvent;
        $node = $workflow->getNodeForEvent($event::class);

        try {
            while (!($event instanceof StopEvent)) {
                $stepId = $branchId . '.' . $node::class;

                $packed = $ctx->step->run($stepId, function () use ($node, $event, $branchState, $workflow, $branchId): array {
                    return $this->executeBranchNode($node, $event, $branchState, $workflow, $branchId);
                });

                if ($packed['interrupted'] ?? false) {
                    $packed = $this->resumeInterruptedBranchNode($ctx, $workflow, $stepId, $node, $event, $branchState, $branchId);
                }

                $event = $this->unpackEvent($packed);
                $branchState = $this->unpackState($packed);
                $workflow->setState($branchState);

                if ($event instanceof ParallelEvent) {
                    $this->executeBranches($workflow, $ctx, $event);
                }

                if ($event instanceof StopEvent) {
                    break;
                }

                $node = $workflow->getNodeForEvent($event::class);
            }
        } catch (WorkflowInterrupt $interrupt) {
            throw new BranchInterrupt($branchId, $interrupt);
        }

        return $event->getResult();
    }

    /**
     * Execute a node inside a parallel branch, catching WorkflowInterrupt.
     */
    protected function executeBranchNode(
        NodeInterface $node,
        Event $event,
        WorkflowState $state,
        WorkflowInterface $workflow,
        string $branchId,
        ?InterruptRequest $resumeRequest = null,
    ): array {
        try {
            $middleware = $workflow->getMiddlewareForNode($node);
            $nodeGen = $this->nodeRunner->run($node, $event, $state, $middleware, $branchId, $resumeRequest);
            foreach ($nodeGen as $_) {}
            $resultEvent = $nodeGen->getReturn();

            return $this->pack($resultEvent, $state);
        } catch (WorkflowInterrupt $interrupt) {
            return [
                'interrupted' => true,
                'state' => base64_encode(serialize($interrupt->getState())),
                'request' => base64_encode(serialize($interrupt->getRequest())),
            ];
        }
    }

    /**
     * Handle interrupt resume inside a branch.
     *
     * @throws StepPendingException
     */
    protected function resumeInterruptedBranchNode(
        Context $ctx,
        WorkflowInterface $workflow,
        string $stepId,
        NodeInterface $node,
        Event $event,
        WorkflowState $state,
        string $branchId,
    ): array {
        $workflowId = $workflow->getWorkflowId();

        $resumeData = $ctx->step->waitForEvent(
            $stepId . '.interrupt',
            'workflow/interrupt/' . $workflowId,
            '7d',
        );

        $resumeRequest = unserialize(base64_decode($resumeData));

        return $ctx->step->run($stepId . '.resumed', function () use (
            $node,
            $event,
            $state,
            $workflow,
            $branchId,
            $resumeRequest,
        ): array {
            return $this->executeBranchNode($node, $event, $state, $workflow, $branchId, $resumeRequest);
        });
    }

    /**
     * Send a resume event to the Deeplinq platform.
     *
     * Call this after a workflow interrupt to deliver the user's response:
     *
     *   DeeplinqExecutor::sendResume($client, $workflowId, $approvalRequest);
     */
    public static function sendResume(
        Client $client,
        string $workflowId,
        InterruptRequest $request,
    ): void {
        $client->sendEvent(new DeeplinqEvent(
            name: 'workflow/interrupt/' . $workflowId,
            data: base64_encode(serialize($request)),
        ));
    }

    protected function pack(Event $event, WorkflowState $state): array
    {
        return [
            'event' => base64_encode(serialize($event)),
            'state' => base64_encode(serialize($state)),
        ];
    }

    protected function unpackEvent(array $packed): Event
    {
        return unserialize(base64_decode($packed['event']));
    }

    protected function unpackState(array $packed): WorkflowState
    {
        return unserialize(base64_decode($packed['state']));
    }

    protected function workflowEnd(WorkflowInterface $workflow): void
    {
        $workflowId = $workflow->getWorkflowId();
        EventBus::emit('workflow-end', $workflow, new WorkflowEnd($workflow->resolveState()), $workflowId);
        EventBus::clear($workflowId);
    }
}
