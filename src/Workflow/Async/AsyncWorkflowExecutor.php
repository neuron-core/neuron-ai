<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Async;

use NeuronAI\Workflow\Interrupt\InterruptRequest;
use NeuronAI\Workflow\Workflow;

/**
 * Interface for async workflow execution adapters.
 *
 * This interface allows integration with various PHP async frameworks (Amp, ReactPHP, Swoole, etc.)
 * by providing a consistent API for non-blocking workflow execution.
 *
 * Async execution works by:
 * 1. Registering async middleware that suspends execution after each node completes
 * 2. Starting the workflow and consuming it within async context
 * 3. Yielding control to the event loop/scheduler between node executions
 *
 * This is specifically for the blocking getResult() operation. Streaming workflows
 * remain synchronous since they already provide iterative, non-blocking event access.
 *
 * @example Basic usage with Amp
 * use NeuronAI\Workflow\Async\AmpWorkflowExecutor;
 *
 * $executor = new AmpWorkflowExecutor();
 * $future = $executor->execute($workflow);
 * $result = $future->await();
 *
 * @example Concurrent workflows
 * use Amp\Future;
 *
 * $executor = new AmpWorkflowExecutor();
 * $futures = [
 *     $executor->execute($workflow1),
 *     $executor->execute($workflow2),
 *     $executor->execute($workflow3),
 * ];
 * $results = Future\await($futures);
 *
 * @example Resuming interrupted workflow
 * $future = $executor->execute($workflow, $resumeRequest);
 * $result = $future->await();
 *
 * @example Streaming remains synchronous (no adapter needed)
 * $handler = $workflow->start();
 * foreach ($handler->streamEvents() as $event) {
 *     // Process events iteratively
 * }
 */
interface AsyncWorkflowExecutor
{
    /**
     * Execute a workflow asynchronously.
     *
     * The executor registers async middleware on the workflow, starts execution,
     * and returns a framework-specific promise/future that resolves to the final state.
     *
     * Suspension happens at node boundaries (after each node completes), allowing
     * the async runtime to schedule other work between node executions.
     *
     * @param Workflow $workflow The workflow to execute
     * @param InterruptRequest|null $resumeRequest Optional resume request for interrupted workflows
     * @return mixed Framework-specific promise/future that resolves to WorkflowState
     *
     * Return types by framework:
     * - Amp: Amp\Future<WorkflowState>
     * - ReactPHP: React\Promise\PromiseInterface
     * - Swoole: Direct WorkflowState (use within go() coroutine)
     */
    public function execute(Workflow $workflow, ?InterruptRequest $resumeRequest = null): mixed;
}
