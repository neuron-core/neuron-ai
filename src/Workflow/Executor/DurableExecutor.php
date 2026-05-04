<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Executor;

use Flowline\Step;
use NeuronAI\Workflow\Events\StopEvent;
use NeuronAI\Workflow\WorkflowInterface;

/**
 * Executes a Neuron workflow durably using Flowline's step model.
 *
 * Each node execution is a durable step persisted by the Flowline platform.
 * On replay (HTTP callback), memoized steps return instantly and execution
 * continues from the first non-memoized step.
 *
 * Checkpoints are base64-encoded PHP serialized blobs that survive JSON
 * transport while preserving full object fidelity (typed Events, rich state).
 *
 * Usage:
 *
 *   $flowlineClient->register(new Task(
 *       id: 'my-workflow',
 *       triggers: [new Event(name: 'workflow/start')],
 *       handler: function (Context $ctx) {
 *           $workflow = new MyWorkflow();
 *           return (new DurableExecutor($ctx->step))->executeDurable($workflow);
 *       },
 *   ));
 */
class DurableExecutor extends WorkflowExecutor
{
    public function __construct(
        private readonly Step $step,
    ) {}

    /**
     * Execute the workflow durably, one node per step.
     *
     * On the first callback, step-0 executes the start node. On each subsequent
     * callback, memoized steps replay instantly and the next unexecuted node runs.
     * When a StopEvent is produced, the workflow result is returned.
     *
     * @return mixed The StopEvent result when the workflow completes
     */
    public function executeDurable(WorkflowInterface $workflow): mixed
    {
        $workflow->initialize();

        $stepIndex = 0;
        $eventBlob = null;
        $stateBlob = null;

        while (true) {
            $result = $this->step->run("step-{$stepIndex}", function () use ($workflow, $eventBlob, $stateBlob) {
                // Restore state from previous step
                if ($stateBlob !== null) {
                    $state = unserialize(base64_decode($stateBlob));
                    $workflow->setState($state);
                }

                $state = $workflow->resolveState();
                $state->set('__workflowId', $workflow->getWorkflowId());

                // Determine event: from checkpoint or start event
                $event = $eventBlob !== null
                    ? unserialize(base64_decode($eventBlob))
                    : $workflow->getStartEvent();

                $node = $workflow->getNodeForEvent($event::class);

                // Execute the node with full middleware and observability
                $generator = $this->executeNode($workflow, $event, $node);

                // Consume streaming events within this step
                foreach ($generator as $_) {
                }
                $nextEvent = $generator->getReturn();

                return [
                    'event_type' => $nextEvent::class,
                    'event_blob' => base64_encode(serialize($nextEvent)),
                    'state_blob' => base64_encode(serialize($workflow->resolveState())),
                ];
            });

            if ($result['event_type'] === StopEvent::class) {
                return unserialize(base64_decode($result['event_blob']))->getResult();
            }

            $eventBlob = $result['event_blob'];
            $stateBlob = $result['state_blob'];
            $stepIndex++;
        }
    }
}
