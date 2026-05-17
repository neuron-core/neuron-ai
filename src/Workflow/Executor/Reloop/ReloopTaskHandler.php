<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Executor\Reloop;

use Closure;
use Reloop\Client;
use Reloop\Context;
use Reloop\Event;
use NeuronAI\Workflow\Executor\WorkflowExecutor;
use NeuronAI\Workflow\Interrupt\InterruptRequest;
use NeuronAI\Workflow\Workflow;
use function base64_encode;
use function serialize;

/**
 * Thin adapter that connects a Neuron Workflow to the Reloop platform.
 *
 * Usage:
 *    $reloop->register(new Task(
 *        id: 'my-workflow',
 *        triggers: [new Event('event/name')],
 *        handler: new ReloopTaskHandler($workflow),
 *    ));
 *
 * With async executor for concurrent parallel branches:
 *    new ReloopTaskHandler(
 *        $workflow,
 *        executorFactory: fn(ReloopStepEngine $engine) => new AsyncExecutor($engine),
 *    );
 *
 * With agent boot callback:
 *    new ReloopTaskHandler(
 *        Agent::make(...),
 *        boot: fn(Agent $agent, Context $ctx) => $agent->chat(new UserMessage($ctx->event->data['prompt']))->run(),
 *    );
 */
class ReloopTaskHandler
{
    /**
     * @param Closure|null $boot Callback to prepare the workflow before execution.
     *   For Agents, pass fn(Agent $agent) => $agent->chat(...)->run().
     *   Receives the workflow and the Deeplinq Context.
     * @param Closure|null $executorFactory Factory that receives a DeeplinqStepEngine
     *   and returns the executor to use. Defaults to WorkflowExecutor.
     */
    public function __construct(
        protected Workflow $workflow,
        protected ?Closure $boot = null,
        protected ?Closure $executorFactory = null,
    ) {
    }

    public function __invoke(Context $ctx): void
    {
        $engine = new ReloopStepEngine($ctx);

        $this->workflow->setExecutor(
            $this->executorFactory instanceof Closure
                ? ($this->executorFactory)($engine)
                : new WorkflowExecutor($engine)
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
        $client->sendEvent(new Event(
            name: 'workflow/interrupt/' . $workflowId,
            data: ['resume' => base64_encode(serialize($request))],
        ));
    }
}
