<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Async;

use NeuronAI\Workflow\Workflow;
use NeuronAI\Workflow\WorkflowHandlerInterface;

/**
 * Interface for async workflow execution adapters.
 *
 * This interface allows integration with various PHP async frameworks (Amp, ReactPHP, Swoole, etc.)
 * by providing a consistent API for non-blocking workflow execution.
 *
 * This is specifically for the blocking getResult() operation. Streaming workflows
 * remain synchronous since they already provide iterative, non-blocking event access.
 */
interface AsyncWorkflowExecutor
{
    /**
     * Execute a workflow asynchronously.
     *
     * The executor starts workflow execution and returns a framework-specific promise/future that resolves to the final state.
     *
     * @return mixed Framework-specific promise/future that resolves to WorkflowState
     */
    public function execute(WorkflowHandlerInterface $handler): mixed;
}
