<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Executor;

use Closure;
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
    /**
     * @param Workflow $workflow The workflow to execute
     * @param Closure|null $boot Callback to prepare the workflow before execution.
     *   For Agents, pass fn(Agent $agent) => $agent->chat(...)->events() to set up the chat mode.
     *   Receives the workflow and must return a Generator.
     */
    public function __construct(
        protected Workflow $workflow,
        protected ?Closure $boot = null,
    ) {
    }

    public function __invoke(Context $ctx): Generator|WorkflowState
    {
        $this->workflow->setExecutor(
            new WorkflowExecutor(new DeeplinqStepEngine($ctx, $this->workflow->getWorkflowId()))
        );

        $events = $this->boot instanceof Closure ? ($this->boot)($this->workflow) : $this->workflow->events();

        yield from $events;

        return $this->workflow->resolveState();
    }
}
