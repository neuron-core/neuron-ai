<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Executor;

use Closure;
use Deeplinq\Context;
use Generator;
use NeuronAI\Workflow\Interrupt\InterruptRequest;
use NeuronAI\Workflow\Workflow;
use NeuronAI\Workflow\WorkflowState;

/**
 * Thin adapter that connects a Neuron Workflow to the Deeplinq platform.
 *
 * Usage:
 *    $deeplinq->register(new Task(
 *        id: 'my-workflow',
 *        triggers: [new Event('event/name')],
 *        handler: new DeeplinqTaskHandler($workflow),
 *    ));
 *
 *    $deeplinq->register(new Task(
 *        id: 'my-workflow',
 *        triggers: [new Event('event/name')],
 *        handler: new DeeplinqTaskHandler(Agent::make(...), fn(Agent $agent, Context $ctx) => $agent->chat(new UserMessage($ctx->event->data['prompt']))->run()),
 *    ));
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

    public function __invoke(Context $ctx): Generator
    {
        $this->workflow->setExecutor(
            new WorkflowExecutor(new DeeplinqStepEngine($ctx, $this->workflow->getWorkflowId()))
        );

        $result = ($this->boot instanceof Closure ? ($this->boot)($this->workflow, $ctx) : $this->workflow->run());

        if ($result instanceof Generator) {
            yield from $result;
        }
    }

    /**
     * Send a resume event to the Deeplinq platform.
     *
     * Call this after a workflow interrupt to deliver the user's response:
     *
     *   DeeplinqStepEngine::sendResume($client, $workflowId, $approvalRequest);
     */
    public static function resume(
        Client $client,
        string $workflowId,
        InterruptRequest $request,
    ): void {
        $client->sendEvent(new DeeplinqEvent(
            name: 'workflow/interrupt/' . $workflowId,
            data: ['resume' => base64_encode(serialize($request))],
        ));
    }
}
