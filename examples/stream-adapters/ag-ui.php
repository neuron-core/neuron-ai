<?php

declare(strict_types=1);

use NeuronAI\Agent\Agent;
use NeuronAI\Chat\Messages\Stream\Adapters\AGUIAdapter;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Providers\Anthropic\Anthropic;
use NeuronAI\Tools\Toolkits\Calculator\CalculatorToolkit;

require_once __DIR__ . '/../../vendor/autoload.php';

// Create agent with tools
$agent = Agent::make()
    ->setAiProvider(
        new Anthropic(
            '',
            'claude-5-sonnet'
        )
    )
    ->addTool(
        CalculatorToolkit::make()
    );

// Stream the response through the Vercel AI SDK adapter. stream() returns a
// generator yielding protocol-formatted lines; pass the adapter as the 2nd arg.
$generator = $agent->stream(new UserMessage('What is the square root of 144?'), new AGUIAdapter(\uniqid()));

foreach ($generator as $line) {
    echo $line;
    \flush();
}
