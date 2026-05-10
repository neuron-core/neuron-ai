<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Executor;

use Closure;
use Deeplinq\Client;
use Deeplinq\Context;
use Deeplinq\Event as DeeplinqEvent;
use NeuronAI\Workflow\Interrupt\InterruptRequest;
use NeuronAI\Workflow\Workflow;

use function base64_encode;
use function serialize;

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
     * @param Closure|null $boot Callback to prepare the workflow before execution.
     *   For Agents, pass fn(Agent $agent) => $agent->chat(...)->run().
     *   Receives the workflow and the Deeplinq Context.
     */
    public function __construct(
        protected Workflow $workflow,
        protected ?Closure $boot = null,
    ) {
    }

    public function __invoke(Context $ctx): void
    {
        $this->workflow->setExecutor(
            new WorkflowExecutor(new DeeplinqStepEngine($ctx))
        );

        ($this->boot instanceof Closure ? ($this->boot)($this->workflow, $ctx) : $this->workflow->run());
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
