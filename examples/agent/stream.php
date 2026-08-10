<?php

declare(strict_types=1);

use NeuronAI\Agent\Agent;
use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\ToolCallChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\ToolResultChunk;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Providers\Anthropic\Anthropic;
use NeuronAI\Tools\Toolkits\Calculator\CalculatorToolkit;

require_once __DIR__ . '/../../vendor/autoload.php';

$agent = Agent::make()
    ->setAiProvider(
        new Anthropic(
            '',
            'claude-3-7-sonnet-latest'
        )
    )
    ->addTool(CalculatorToolkit::make());

// stream() returns a Generator yielding Neuron chunks; its getReturn() is the
// final AgentState. No handler to drive — iterate directly.
$generator = $agent->stream(
    new UserMessage('Hi, using the tools you have, try to calculate the square root of 16!')
);

foreach ($generator as $chunk) {
    if ($chunk instanceof ToolCallChunk) {
        echo "\n- Calling " . $chunk->tool->getName() . ' with: ' . \json_encode($chunk->tool->getInputs());
        continue;
    }

    if ($chunk instanceof ToolResultChunk) {
        echo "\n";
        continue;
    }

    if ($chunk instanceof TextChunk) {
        echo $chunk->content;
    }
}

echo \PHP_EOL;
