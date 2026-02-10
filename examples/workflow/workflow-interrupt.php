<?php

declare(strict_types=1);

use NeuronAI\Tests\Workflow\Stubs\InterruptableNode;
use NeuronAI\Tests\Workflow\Stubs\NodeForSecond;
use NeuronAI\Tests\Workflow\Stubs\NodeOne;
use NeuronAI\Workflow\Interrupt\WorkflowInterrupt;
use NeuronAI\Workflow\Persistence\FilePersistence;
use NeuronAI\Workflow\Workflow;

require_once __DIR__ . '/../../vendor/autoload.php';

$persistence = new FilePersistence(__DIR__);

$workflow = Workflow::make(persistence: $persistence)
    ->addNodes([
        new NodeOne(),
        new InterruptableNode(),
        new NodeForSecond(),
    ]);

$approvalRequest = null;
$resumeToken = null;

// Run the workflow and catch the interruption
try {
    $finalState = $workflow->init()->run();
} catch (WorkflowInterrupt $interrupt) {
    $approvalRequest = $interrupt->getRequest();
    $resumeToken = $interrupt->getResumeToken();

    // The resume token is auto-generated and available from the interrupt
    echo "Resume token: {$resumeToken}\n";
}

/*
 * ---------------------------------------
 * Imagine a new execution cycle start here
 * ---------------------------------------
 *
 * Create the workflow instance with the resumeToken and the same persistence component.
 */
$workflow = Workflow::make(persistence: $persistence, resumeToken: $resumeToken)
    ->addNodes([
        new NodeOne(),
        new InterruptableNode(),
        new NodeForSecond(),
    ]);

// Resume the workflow providing the modified approval request
$finalState = $workflow->init($approvalRequest)->run();

// It should print "completed"
echo $finalState->get('received_feedback').\PHP_EOL;
