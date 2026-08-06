<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Interrupt;

use NeuronAI\Exceptions\WorkflowException;

/**
 * @internal
 *
 * Internal control-flow signal thrown by {@see \NeuronAI\Workflow\Node::interrupt()}
 * and by pausing middleware to unwind to the step boundary
 * ({@see \NeuronAI\Workflow\Executor\WorkflowExecutor::runNode}), which converts
 * it into an {@see \NeuronAI\Workflow\Events\InterruptEvent}.
 *
 * This is never persisted and never reaches user code — a paused workflow is
 * surfaced to callers as an interrupted {@see \NeuronAI\Workflow\WorkflowState},
 * not as a thrown exception. It carries only the request that describes the pause; all
 * other resume context (node, state, branch) is derived by the executor from
 * its traversal context and step replay.
 */
class WorkflowInterrupt extends WorkflowException
{
    public function __construct(
        protected InterruptRequest $request,
    ) {
        parent::__construct($request->getMessage());
    }

    /**
     * Get the structured interrupt request.
     */
    public function getRequest(): InterruptRequest
    {
        return $this->request;
    }
}
