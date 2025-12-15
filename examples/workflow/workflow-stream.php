<?php

declare(strict_types=1);

use NeuronAI\Tests\Workflow\Stubs\NodeForSecond;
use NeuronAI\Tests\Workflow\Stubs\NodeOne;
use NeuronAI\Tests\Workflow\Stubs\NodeTwo;
use NeuronAI\Tests\Workflow\Stubs\SecondEvent;
use NeuronAI\Workflow\Workflow;

require_once __DIR__ . '/../../vendor/autoload.php';

$workflow = new Workflow();

$workflow->addNodes([
    new NodeOne(),
    new NodeTwo(), // <-- This node streams the SecondEvent
    new NodeForSecond(),
]);

// Draw the workflow graph
echo $workflow->export().\PHP_EOL.\PHP_EOL.\PHP_EOL;

$handler = $workflow->init();

foreach ($handler->events() as $event) {
    if ($event instanceof SecondEvent) {
        echo \PHP_EOL.'- ' . $event->message.\PHP_EOL;
    }
}

$finalState = $handler->run();

// It should print "Second complete"
echo $finalState->get('final_second_message').\PHP_EOL;
