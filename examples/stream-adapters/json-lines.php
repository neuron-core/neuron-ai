<?php

declare(strict_types=1);

use NeuronAI\Agent\Agent;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Providers\Anthropic\Anthropic;
use NeuronAI\Stream\Adapters\JSONLinesAdapter;
use NeuronAI\Tools\Toolkits\Calculator\CalculatorToolkit;

require_once __DIR__ . '/../../vendor/autoload.php';

/**
 * Example: Streaming with JSON Lines format
 *
 * This example demonstrates how to use the JSONLinesAdapter to format
 * Neuron's streaming output as newline-delimited JSON (NDJSON).
 *
 * Frontend usage (Vanilla JS):
 * ```javascript
 * const response = await fetch('/api/chat', {
 *   method: 'POST',
 *   body: JSON.stringify({ message: 'Hello' })
 * });
 *
 * const reader = response.body.getReader();
 * const decoder = new TextDecoder();
 *
 * while (true) {
 *   const { value, done } = await reader.read();
 *   if (done) break;
 *
 *   const lines = decoder.decode(value).split('\n');
 *   for (const line of lines) {
 *     if (line.trim()) {
 *       const data = JSON.parse(line);
 *       if (data.type === 'text') {
 *         console.log(data.content);
 *       }
 *     }
 *   }
 * }
 * ```
 */

// Create agent with tools
$agent = Agent::make()
    ->setAiProvider(
        new Anthropic(
            '',
            'claude-3-7-sonnet-latest'
        )
    )
    ->addTool(CalculatorToolkit::make());

// Create the JSON Lines adapter
$adapter = new JSONLinesAdapter();

// Set HTTP headers for NDJSON
foreach ($adapter->getHeaders() as $key => $value) {
    \header("$key: $value");
}

// Stream the response with the adapter
foreach ($agent->streamWithAdapter(
    $adapter,
    new UserMessage('Calculate 15 * 23 for me')
) as $line) {
    echo $line;
    \flush();
}
