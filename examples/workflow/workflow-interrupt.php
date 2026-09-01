<?php

declare(strict_types=1);

use NeuronAI\Tests\Workflow\Stub\InterruptableNode;
use NeuronAI\Tests\Workflow\Stub\NodeForSecond;
use NeuronAI\Tests\Workflow\Stub\NodeOne;
use NeuronAI\Workflow\Persistence\FilePersistence;
use NeuronAI\Workflow\Resume\ResumeInput;
use NeuronAI\Workflow\Workflow;

require_once __DIR__ . '/../../vendor/autoload.php';

$persistence = new FilePersistence(__DIR__);

$workflow = Workflow::make()
    ->setPersistence($persistence)
    ->addNodes([
        new NodeOne(),
        new InterruptableNode(),
        new NodeForSecond(),
    ]);

// Run the workflow. When a node calls interrupt(), execution pauses and the
// returned state is marked interrupted — no exception is thrown to the caller.
$state = $workflow->run();

// The workflow ID is assigned by the engine when the run starts, and readable
// from the workflow instance afterwards — it is also the handle to resume a
// suspended run.
$workflowId = $workflow->getWorkflowId();
$approvalRequest = null;

if ($state->isInterrupted()) {
    $approvalRequest = $state->getInterruptRequest();
    echo "Paused: {$approvalRequest->getMessage()}\n";
    echo "Workflow ID: {$workflowId}\n";
}

/*
 * ---------------------------------------
 * Imagine a new execution cycle starts here
 * ---------------------------------------
 *
 * Rebuild the workflow with the workflow ID and the same persistence, then
 * resume by delivering an addressed ResumeInput carrying the answer to the
 * pause (a human decision, an event body, etc.). The outbound
 * InterruptRequest is never passed back in: it described the pause, the payload
 * satisfies it.
 *
 * For tool approval, the payload is keyed by the tool callId —
 * e.g. ['call_123' => 'approve'] or ['call_456' => ['reject', 'too expensive']].
 * The complete current decision set is restated on every resume.
 */
$workflow = Workflow::make(workflowId: $workflowId)
    ->setPersistence($persistence)
    ->addNodes([
        new NodeOne(),
        new InterruptableNode(),
        new NodeForSecond(),
    ]);

// The inbound payload — the answer to the pause.
$payload = ['action_id' => 'approve'];

$finalState = $workflow->resume([
    ResumeInput::event($state->getSuspensions()[0]->id, $payload),
]);

// It should print "completed"
echo $finalState->get('received_feedback') . \PHP_EOL;
