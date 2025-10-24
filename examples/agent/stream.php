<?php

declare(strict_types=1);

use NeuronAI\Agent\Middleware\ToolApprovalMiddleware;
use NeuronAI\Agent\Nodes\ToolNode;
use NeuronAI\Agent\ToolCallChunk;
use NeuronAI\Agent\ToolResultChunk;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Providers\Anthropic\Anthropic;
use NeuronAI\Tools\ToolInterface;
use NeuronAI\Tools\Toolkits\Calculator\CalculatorToolkit;
use NeuronAI\Workflow\WorkflowInterrupt;

require_once __DIR__ . '/../../vendor/autoload.php';


$agent = \NeuronAI\Agent\Agent::make()
    ->setAiProvider(
        new Anthropic(
            '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',
            'claude-3-7-sonnet-latest'
        )
    )
    ->addTool(
        CalculatorToolkit::make()
    )
    ->middleware(
        ToolNode::class,
        new ToolApprovalMiddleware(),
    );

function process_response($response): void {
    if ($response instanceof ToolCallChunk) {
        echo \PHP_EOL.\PHP_EOL.\array_reduce($response->tools, function (string $carry, ToolInterface $tool): string {
                return $carry . '- Calling ' . $tool->getName() . ' with: ' . \json_encode($tool->getInputs()) . \PHP_EOL;
            }, '').\PHP_EOL;
        return;
    } elseif ($response instanceof ToolResultChunk) {
        return;
    }

    echo $response->content;
}

try {
    $result = $agent->stream(
        new UserMessage('Hi, using the tool you have, calculate the square root of 16!')
    );
    /** @var \NeuronAI\Agent\StreamChunk $response */
    foreach ($result as $response) {
        process_response($response);
    }
} catch (WorkflowInterrupt $interrupt) {
    echo "\n".$interrupt->getRequest()->getReason()."\n";

    foreach ($interrupt->getRequest()->getPendingActions() as $action) {
        echo "- {$action->name}: {$action->description}\n";
        $action->reject('The user denied operation');
    }

    $result = $agent->stream(interrupt: $interrupt->getRequest());
    /** @var \NeuronAI\Agent\StreamChunk $response */
    foreach ($result as $response) {
        process_response($response);
    }
}

echo \PHP_EOL;
