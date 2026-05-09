<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Executor;

use Deeplinq\Context;
use Generator;
use NeuronAI\Workflow\Workflow;
use NeuronAI\Workflow\WorkflowState;

/**
 * Thin adapter that connects a Neuron Workflow to the Deeplinq platform.
 *
 * Usage:
 *   $deeplinq->register(new Task(
 *       id: 'my-workflow',
 *       triggers: [new Event('event/name')],
 *       handler: new DeeplinqTaskHandler($workflow),
 *   ));
 */
class DeeplinqTaskHandler
{
    public function __construct(
        protected Workflow $workflow,
    ) {
    }

    public function __invoke(Context $ctx): Generator|WorkflowState
    {
        $this->workflow->setExecutor(
            new WorkflowExecutor(new DeeplinqStepEngine($ctx, $this->workflow->getWorkflowId()))
        );

        yield from $this->workflow->events();

        return $this->workflow->resolveState();
    }
}
