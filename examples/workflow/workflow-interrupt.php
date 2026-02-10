<?php

declare(strict_types=1);

use NeuronAI\Tests\Workflow\Stubs\InterruptableNode;
use NeuronAI\Tests\Workflow\Stubs\NodeForSecond;
use NeuronAI\Tests\Workflow\Stubs\NodeOne;
use NeuronAI\Workflow\Interrupt\WorkflowInterrupt;
use NeuronAI\Workflow\Persistence\FilePersistence;
use NeuronAI\Workflow\Workflow;
use NeuronAI\Workflow\WorkflowState;

require_once __DIR__ . '/../../vendor/autoload.php';

$persistence = new FilePersistence(__DIR__);

$workflow = Workflow::make(new WorkflowState(), $persistence)
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
    echo "Workflow interrupted at ".$interrupt->getNode()::class."\n";
}

// Resume the workflow providing external data
$finalState = $workflow->init($approvalRequest)->run();

// It should print "approved"
echo $finalState->get('received_feedback').\PHP_EOL;
