<?php

declare(strict_types=1);

use NeuronAI\Tests\Workflow\Stubs\FirstEvent;
use NeuronAI\Tests\Workflow\Stubs\InterruptableNode;
use NeuronAI\Tests\Workflow\Stubs\NodeForSecond;
use NeuronAI\Tests\Workflow\Stubs\NodeOne;
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
    SecondEvent::class => new NodeForSecond(),
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

// It should print "approved"
echo $state->get('received_feedback');

echo \PHP_EOL.\PHP_EOL.\PHP_EOL.$workflow->export();
