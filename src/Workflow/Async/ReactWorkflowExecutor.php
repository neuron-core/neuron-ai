<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Async;

use NeuronAI\Workflow\Interrupt\InterruptRequest;
use NeuronAI\Workflow\Workflow;
use NeuronAI\Workflow\WorkflowState;
use React\EventLoop\LoopInterface;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;

/**
 * ReactPHP async framework adapter for workflow execution.
 *
 * This adapter integrates Neuron workflows with ReactPHP's event loop.
 * It schedules workflow execution on the event loop, allowing nodes to use
 * ReactPHP's promise-based async operations naturally.
 *
 * Requirements:
 * - react/event-loop: ^1.0
 * - react/promise: ^3.0
 *
 * @example Basic usage
 * use React\EventLoop\Loop;
 * use NeuronAI\Workflow\Async\ReactWorkflowExecutor;
 *
 * $loop = Loop::get();
 * $executor = new ReactWorkflowExecutor($loop);
 *
 * $promise = $executor->execute($workflow);
 * $promise->then(function($result) {
 *     var_dump($result);
 * });
 *
 * @example Concurrent workflows
 * use React\Promise\all;
 *
 * $promise1 = $executor->execute($workflow1);
 * $promise2 = $executor->execute($workflow2);
 * $promise3 = $executor->execute($workflow3);
 *
 * all([$promise1, $promise2, $promise3])->then(function($results) {
 *     [$result1, $result2, $result3] = $results;
 *     // All workflows completed
 * });
 *
 * @example Error handling
 * $promise = $executor->execute($workflow);
 * $promise->then(
 *     function($result) {
 *         echo "Success!";
 *     },
 *     function($error) {
 *         echo "Error: " . $error->getMessage();
 *     }
 * );
 *
 * @example Resuming interrupted workflow
 * $promise = $executor->execute($workflow, $resumeRequest);
 */
class ReactWorkflowExecutor implements AsyncWorkflowExecutor
{
    public function __construct(
        protected LoopInterface $loop
    ) {
    }

    /**
     * Execute workflow asynchronously using ReactPHP.
     *
     * This method schedules the workflow execution on the ReactPHP event loop.
     * Nodes running within this context can use ReactPHP's promise-based async
     * operations, and they will naturally integrate with the event loop.
     *
     * The executor simply provides the event loop context.
     * Nodes leverage it by using ReactPHP's async-aware libraries.
     *
     * @param Workflow $workflow The workflow to execute
     * @param InterruptRequest|null $resumeRequest Optional resume request for interrupted workflows
     * @return PromiseInterface Promise that resolves to WorkflowState
     */
    public function execute(Workflow $workflow, ?InterruptRequest $resumeRequest = null): PromiseInterface
    {
        $deferred = new Deferred();

        try {
            // Start workflow execution on next tick to ensure we're in event loop context
            $this->loop->futureTick(function () use ($workflow, $resumeRequest, $deferred) {
                try {
                    $handler = $workflow->start($resumeRequest);
                    $result = $handler->getResult();
                    $deferred->resolve($result);
                } catch (\Throwable $e) {
                    $deferred->reject($e);
                }
            });
        } catch (\Throwable $e) {
            $deferred->reject($e);
        }

        return $deferred->promise();
    }
}
