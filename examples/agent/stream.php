<?php

use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolCallResultMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Providers\Anthropic\Anthropic;
use NeuronAI\Tools\ToolInterface;
use NeuronAI\Tools\Toolkits\Calculator\CalculatorToolkit;

require_once __DIR__ . '/../../vendor/autoload.php';


$result = \NeuronAI\Agent\Agent::make()
    ->setAiProvider(
        new Anthropic(
            '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',
            'claude-3-7-sonnet-latest'
        )
    )
    ->addTool(
        CalculatorToolkit::make()
    )
    ->stream(
        new UserMessage('Hi, using the tool you have, calculate the square root of 16!')
    );

/** @var \NeuronAI\Agent\StreamChunk $response */
foreach ($result as $response) {
    if ($response instanceof ToolCallMessage) {
        echo PHP_EOL.\array_reduce($response->getTools(), function ($carry, ToolInterface $tool): string {
            return $carry . 'Calling ' . $tool->getName() . PHP_EOL;
        }, '');
        continue;
    }
    if ($response instanceof ToolCallResultMessage) {
        continue;
    }
    echo $response->content;
}

echo PHP_EOL;
