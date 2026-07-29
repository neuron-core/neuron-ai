<?php

declare(strict_types=1);

use NeuronAI\Agent\Middleware\ToolApproval;
use NeuronAI\Agent\Nodes\ToolNode;
use NeuronAI\Chat\Messages\Stream\Chunks\ToolCallChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\ToolResultChunk;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Providers\Anthropic\Anthropic;
use NeuronAI\Tools\Toolkits\Calculator\CalculatorToolkit;
use NeuronAI\Workflow\Interrupt\WorkflowInterrupt;

require_once __DIR__ . '/../../vendor/autoload.php';


$agent = \NeuronAI\Agent\Agent::make()
    ->setAiProvider(
        new Anthropic(
            '',
            'claude-3-7-sonnet-latest'
        )
    )
    ->addTool(CalculatorToolkit::make())
    ->addMiddleware(ToolNode::class, new ToolApproval());

function process_response($response): void
{
    if ($response instanceof ToolCallChunk) {
        echo "\n- Calling " . $response->tool->getName() . ' with: ' . \json_encode($response->tool->getInputs());
        return;
    } elseif ($response instanceof ToolResultChunk) {
        echo "\n";
    }

    echo $response->content;
}

$interruptRequest = null;
$payload = null;

try {
    stream:
    if ($payload === null) {
        $result = $agent->stream(
            new UserMessage('Hi, using the tools you have, try to calculate the square root of 16!')
        );
    } else {
        $result = $agent->stream(payload: $payload);
    }

    /** @var \NeuronAI\Chat\Messages\Stream\Chunks\TextChunk $response */
    foreach ($result as $response) {
        process_response($response);
    }
} catch (WorkflowInterrupt $interrupt) {
    $interruptRequest = $interrupt->getRequest();

    echo "\nAgent interruption\n";
    echo $interruptRequest->getMessage()."\n\n";

    // Build an incremental payload keyed by the action id (the tool callId).
    $payload = [];
    foreach ($interruptRequest->getActions() as $action) {
        echo "- {$action->name}: {$action->description}\n";
        $payload[$action->id] = ['reject', 'The user denied operation'];
    }
    goto stream;
}

echo \PHP_EOL;
