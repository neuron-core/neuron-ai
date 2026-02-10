<?php

declare(strict_types=1);

use NeuronAI\Tests\Workflow\Stubs\InterruptableNode;
use NeuronAI\Tests\Workflow\Stubs\NodeForSecond;
use NeuronAI\Tests\Workflow\Stubs\NodeOne;
use NeuronAI\Workflow\Persistence\FilePersistence;
use NeuronAI\Workflow\Workflow;
use NeuronAI\Workflow\WorkflowInterrupt;
use NeuronAI\Workflow\WorkflowState;

require_once __DIR__ . '/../../vendor/autoload.php';

$persistence = new FilePersistence(__DIR__);

$workflow = Workflow::make(new WorkflowState(), $persistence)
    ->addNodes([
        new NodeOne(),
        new InterruptableNode(),
        new NodeForSecond(),
    ]);

// Draw the workflow graph
echo $workflow->export()."\n\n\n";

// Run the workflow and catch the interruption
try {
    $finalState = $workflow->init()->run();
} catch (WorkflowInterrupt $interrupt) {
    // The resume token is auto-generated and available from the interrupt
    echo "Resume token: ".$interrupt->getResumeToken().\PHP_EOL;
    echo "Workflow interrupted at ".$interrupt->getNode()::class.\PHP_EOL;

    // Resume the workflow providing external data
    $finalState = $workflow->init($interrupt->getRequest())->run();

    // It should print "approved"
    echo $finalState->get('received_feedback').\PHP_EOL;
}
