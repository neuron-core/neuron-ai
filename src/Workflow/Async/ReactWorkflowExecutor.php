<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Async;

use NeuronAI\Workflow\Workflow;
use NeuronAI\Workflow\WorkflowHandlerInterface;
use NeuronAI\Workflow\WorkflowState;
use React\EventLoop\LoopInterface;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use Throwable;

class ReactWorkflowExecutor implements AsyncWorkflowExecutor
{
    public function __construct(
        protected LoopInterface $loop
    ) {
    }

    /**
     * @return PromiseInterface Promise that resolves to WorkflowState
     */
    public function execute(WorkflowHandlerInterface $handler): PromiseInterface
    {
        $deferred = new Deferred();

        try {
            // Start workflow execution on the next tick to ensure we're in the event loop context
            $this->loop->futureTick(function () use ($handler, $deferred): void {
                try {
                    $result = $handler->getResult();
                    $deferred->resolve($result);
                } catch (Throwable $e) {
                    $deferred->reject($e);
                }
            });
        } catch (Throwable $e) {
            $deferred->reject($e);
        }

        return $deferred->promise();
    }
}
