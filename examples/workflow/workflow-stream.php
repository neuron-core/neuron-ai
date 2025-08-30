<?php

declare(strict_types=1);

use NeuronAI\Tests\Workflow\Stubs\InterruptableNode;
use NeuronAI\Tests\Workflow\Stubs\NodeForSecond;
use NeuronAI\Tests\Workflow\Stubs\NodeOne;
use NeuronAI\Tests\Workflow\Stubs\NodeTwo;
use NeuronAI\Tests\Workflow\Stubs\SecondEvent;
use NeuronAI\Workflow\Persistence\FilePersistence;
use NeuronAI\Workflow\Workflow;
use NeuronAI\Workflow\WorkflowInterrupt;
use NeuronAI\Workflow\WorkflowState;

require_once __DIR__ . '/../../vendor/autoload.php';

$persistence = new FilePersistence(__DIR__);

$workflow = new Workflow(new WorkflowState(), $persistence, 'test_workflow');

$workflow->addNodes([
    new NodeOne(),
    new NodeTwo(),
    new NodeForSecond(),
]);

// Draw the workflow graph
echo $workflow->export().\PHP_EOL.\PHP_EOL.\PHP_EOL;

$handler = $workflow->start();

foreach ($handler->streamEvents() as $event) {
    if ($event instanceof SecondEvent) {
        echo \PHP_EOL.'- ' . $event->message.\PHP_EOL;
    }
}

$finalState = $handler->getResult();

// It should print "Second complete"
echo $finalState->get('final_second_message').\PHP_EOL;
