<?php

declare(strict_types=1);

use NeuronAI\Tests\Workflow\Stubs\InterruptableNode;
use NeuronAI\Tests\Workflow\Stubs\NodeForSecond;
use NeuronAI\Tests\Workflow\Stubs\NodeOne;
use NeuronAI\Workflow\Persistence\FilePersistence;
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

// The resume token is auto-generated and available from the workflow instance.
$workflowId = $workflow->getWorkflowId();
$approvalRequest = null;

if ($state->isInterrupted()) {
    $approvalRequest = $state->getInterruptRequest();
    echo "Paused: {$approvalRequest->getMessage()}\n";
    echo "Resume token: {$workflowId}\n";
}

/*
 * ---------------------------------------
 * Imagine a new execution cycle starts here
 * ---------------------------------------
 *
 * Rebuild the workflow with the resume token and the same persistence, then
 * resume by delivering an inbound PAYLOAD — a plain array carrying the answer
 * to the pause (a human decision, an event body, etc.). The outbound
 * InterruptRequest is never passed back in: it described the pause, the payload
 * satisfies it.
 *
 * For an ApprovalRequest, generatePayload() turns the human's decisions into
 * that array — e.g. ['action_id' => 'approve'].
 */
$workflow = Workflow::make(resumeToken: $workflowId)
    ->setPersistence($persistence)
    ->addNodes([
        new NodeOne(),
        new InterruptableNode(),
        new NodeForSecond(),
    ]);

// The inbound payload — the answer to the pause.
$payload = ['action_id' => 'approve'];

$finalState = $workflow->resume($payload);

// It should print "completed"
echo $finalState->get('received_feedback') . \PHP_EOL;
