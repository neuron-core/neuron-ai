<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Async;

use Amp\Future;
use NeuronAI\Workflow\Workflow;
use NeuronAI\Workflow\WorkflowHandlerInterface;
use NeuronAI\Workflow\WorkflowState;

use function Amp\async;

class AmpWorkflowExecutor implements AsyncWorkflowExecutor
{
    /**
     * This method wraps the workflow execution in an Amp async context (Fiber).
     * Nodes running within this context can use any Amp async operations
     * (HTTP client, database, file I/O, delay(), etc.), and they will properly
     * suspend the Fiber, allowing other workflows to execute concurrently.
     *
     * The executor simply provides the async runtime infrastructure.
     * Nodes leverage it by using Amp's async-aware libraries.
     *
     * @return Future<WorkflowState>
     */
    public static function execute(WorkflowHandlerInterface $handler): Future
    {
        return async($handler->getResult(...));
    }
}
