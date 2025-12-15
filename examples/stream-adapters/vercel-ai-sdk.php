<?php

declare(strict_types=1);

use NeuronAI\Agent\Agent;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Providers\Anthropic\Anthropic;
use NeuronAI\Chat\Messages\Stream\Adapters\VercelAIAdapter;
use NeuronAI\Tools\Toolkits\Calculator\CalculatorToolkit;

require_once __DIR__ . '/../../vendor/autoload.php';

/**
 * Example: Streaming with Vercel AI SDK Data Stream Protocol
 *
 * This example demonstrates how to use the VercelAIAdapter to format
 * Neuron's streaming output for direct consumption by Vercel AI SDK
 * in React/Next.js applications.
 *
 * Frontend usage (React):
 * ```javascript
 * import { useChat } from 'ai/react';
 *
 * export default function Chat() {
 *   const { messages, input, handleInputChange, handleSubmit } = useChat({
 *     api: '/api/chat', // Your PHP endpoint
 *   });
 *
 *   return (
 *     <div>
 *       {messages.map(m => (
 *         <div key={m.id}>{m.content}</div>
 *       ))}
 *       <form onSubmit={handleSubmit}>
 *         <input value={input} onChange={handleInputChange} />
 *       </form>
 *     </div>
 *   );
 * }
 * ```
 */

// Create agent with tools
$handler = Agent::make()
    ->setAiProvider(
        new Anthropic(
            '',
            'claude-3-7-sonnet-latest'
        )
    )
    ->addTool(
        CalculatorToolkit::make()
    )
    ->stream(
        new UserMessage('What is the square root of 144?')
    );

// Initialize the streaming with the adapter
$stream = $handler->events(new VercelAIAdapter());

// Process the response
foreach ($stream as $line) {
    echo $line;
    \flush();
}
