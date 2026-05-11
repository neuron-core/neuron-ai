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
 * With async executor for concurrent parallel branches:
 *    new DeeplinqTaskHandler(
 *        $workflow,
 *        executorFactory: fn(DeeplinqStepEngine $engine) => new AsyncExecutor($engine),
 *    );
 *
 * With agent boot callback:
 *    new DeeplinqTaskHandler(
 *        Agent::make(...),
 *        boot: fn(Agent $agent, Context $ctx) => $agent->chat(new UserMessage($ctx->event->data['prompt']))->run(),
 *    );
 */
class DeeplinqTaskHandler
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
        $engine = new DeeplinqStepEngine($ctx);

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
        $client->sendEvent(new DeeplinqEvent(
            name: 'workflow/interrupt/' . $workflowId,
            data: ['resume' => base64_encode(serialize($request))],
        ));
    }
}
