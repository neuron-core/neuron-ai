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

$workflow = Workflow::make(new WorkflowState(), $persistence, 'test_workflow')
    ->addNodes([
        new NodeOne(),
        new InterruptableNode(),
        new NodeForSecond(),
    ]);

// Draw the workflow graph
echo $workflow->export().\PHP_EOL.\PHP_EOL.\PHP_EOL;

// Run the workflow and catch the interruption
try {
    $finalState = $workflow->start()->getResult();
} catch (WorkflowInterrupt $interrupt) {
    // Verify interrupt was saved
    $savedInterrupt = $persistence->load('test_workflow');
    echo "Workflow interrupted at ".$savedInterrupt->getCurrentNode()::class.\PHP_EOL;
}

// Resume the workflow providing external data
$finalState = $workflow->start(true, 'approved')->getResult();

// It should print "approved"
echo $finalState->get('received_feedback').\PHP_EOL;
