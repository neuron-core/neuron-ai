<?php

declare(strict_types=1);

use NeuronAI\Tests\Workflow\Stubs\FirstEvent;
use NeuronAI\Tests\Workflow\Stubs\InterruptableNode;
use NeuronAI\Tests\Workflow\Stubs\NodeOne;
use NeuronAI\Tests\Workflow\Stubs\NodeTwo;
use NeuronAI\Tests\Workflow\Stubs\SecondEvent;
use NeuronAI\Workflow\Persistence\FilePersistence;
use NeuronAI\Workflow\StartEvent;
use NeuronAI\Workflow\Workflow;
use NeuronAI\Workflow\WorkflowInterrupt;

require_once __DIR__ . '/../../vendor/autoload.php';


$persistence = new FilePersistence(__DIR__);

$workflow = new Workflow($persistence, 'test_workflow');

$workflow->addNodes([
    StartEvent::class => new NodeOne(),
    FirstEvent::class => new InterruptableNode(),
    SecondEvent::class => new NodeTwo(),
]);

// Run the workflow and catch the interruption
try {
    $workflow->run();
} catch (WorkflowInterrupt $interrupt) {
    // Verify interrupt was saved
    $savedInterrupt = $persistence->load('test_workflow');
    echo "Workflow interrupted at ".$savedInterrupt->getCurrentNode()::class.\PHP_EOL;
}

// Resume the workflow providing external data
$state = $workflow->resume('approved');

// Print the final value
echo $state->get('received_feedback').\PHP_EOL.\PHP_EOL.\PHP_EOL; // It should print "approved"

echo $workflow->export();
