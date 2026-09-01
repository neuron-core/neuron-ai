<?php

declare(strict_types=1);

use NeuronAI\Tests\Workflow\Stub\NodeForSecond;
use NeuronAI\Tests\Workflow\Stub\NodeOne;
use NeuronAI\Tests\Workflow\Stub\NodeTwo;
use NeuronAI\Tests\Workflow\Stub\SecondEvent;
use NeuronAI\Workflow\Workflow;

require_once __DIR__ . '/../../vendor/autoload.php';

$workflow = new Workflow();

$workflow->addNodes([
    new NodeOne(),
    new NodeTwo(), // <-- This node streams the SecondEvent
    new NodeForSecond(),
]);

$generator = $workflow->events();

foreach ($generator as $event) {
    if ($event instanceof SecondEvent) {
        echo \PHP_EOL.'- ' . $event->message.\PHP_EOL;
    }
}

$finalState = $generator->getReturn();

// It should print "Second complete"
echo $finalState->get('final_second_message').\PHP_EOL;
