<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Async;

use Amp\Future;
use NeuronAI\Workflow\Interrupt\InterruptRequest;
use NeuronAI\Workflow\Workflow;
use NeuronAI\Workflow\WorkflowState;

use function Amp\async;

/**
 * Amp async framework adapter for workflow execution.
 *
 * This adapter integrates Neuron workflows with Amp v3's fiber-based async runtime.
 * It registers FiberAsyncMiddleware to inject suspension points at node boundaries,
 * allowing the Amp scheduler to execute other concurrent tasks between node executions.
 *
 * Requirements:
 * - amphp/amp: ^3.0
 *
 * @example Basic usage
 * use NeuronAI\Workflow\Async\AmpWorkflowExecutor;
 *
 * $executor = new AmpWorkflowExecutor();
 * $future = $executor->execute($workflow);
 * $result = $future->await();
 *
 * @example Concurrent workflow execution
 * use Amp\Future;
 * use NeuronAI\Workflow\Async\AmpWorkflowExecutor;
 *
 * $executor = new AmpWorkflowExecutor();
 *
 * $future1 = $executor->execute($workflow1);
 * $future2 = $executor->execute($workflow2);
 * $future3 = $executor->execute($workflow3);
 *
 * // Wait for all to complete
 * [$result1, $result2, $result3] = Future\await([$future1, $future2, $future3]);
 *
 * @example Resuming interrupted workflow
 * $future = $executor->execute($workflow, $resumeRequest);
 * $result = $future->await();
 *
 * @example Advanced: Custom async operations
 * use function Amp\async;
 *
 * $futures = [];
 * foreach ($workflows as $workflow) {
 *     $futures[] = async(fn() => $executor->execute($workflow)->await());
 * }
 * $results = Future\await($futures);
 */
class AmpWorkflowExecutor implements AsyncWorkflowExecutor
{
    /**
     * Execute workflow asynchronously using Amp.
     *
     * This method wraps the workflow execution in an Amp async context (Fiber).
     * Nodes running within this context can use any Amp async operations
     * (HTTP client, database, file I/O, delay(), etc.) and they will properly
     * suspend the Fiber, allowing other workflows to execute concurrently.
     *
     * The executor simply provides the async runtime infrastructure.
     * Nodes leverage it by using Amp's async-aware libraries.
     *
     * @param Workflow $workflow The workflow to execute
     * @param InterruptRequest|null $resumeRequest Optional resume request for interrupted workflows
     * @return Future<WorkflowState>
     */
    public function execute(Workflow $workflow, ?InterruptRequest $resumeRequest = null): Future
    {
        return async(function () use ($workflow, $resumeRequest): WorkflowState {
            // Start and execute the workflow within async context
            // Nodes can now use any Amp async operations
            $handler = $workflow->start($resumeRequest);
            return $handler->getResult();
        });
    }
}

