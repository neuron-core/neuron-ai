<?php

declare(strict_types=1);

/**
 * Example: Laravel API endpoint with Vercel AI SDK adapter
 *
 * This example shows how to integrate the stream adapter
 * in a Laravel application.
 */

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use NeuronAI\Agent\Agent;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Providers\Anthropic\Anthropic;
use NeuronAI\Chat\Messages\Stream\Adapters\VercelAIAdapter;
use NeuronAI\Tools\Toolkits\Calculator\CalculatorToolkit;

// routes/api.php
Route::post('/chat', function (Request $request) {
    // Validate request
    $validated = $request->validate([
        'message' => 'required|string|max:1000',
    ]);

    // Create agent
    $agent = Agent::make()
        ->setAiProvider(
            new Anthropic(
                config('services.anthropic.api_key'),
                'claude-3-7-sonnet-latest'
            )
        )
        ->addTool(CalculatorToolkit::make());

    $adapter = new VercelAIAdapter();

    $stream = $agent->streamWithAdapter(
        $adapter,
        new UserMessage($validated['message'])
    );

    // Return streaming response
    return response()->stream(
        function () use ($stream) {
            foreach ($stream as $line) {
                echo $line;
                \ob_flush();
                \flush();
            }
        },
        200,
        $adapter->getHeaders()
    );
});

/**
 * Frontend (React with Vercel AI SDK):
 *
 * ```javascript
 * import { useChat } from 'ai/react';
 *
 * export default function Chat() {
 *   const { messages, input, handleInputChange, handleSubmit, isLoading } = useChat({
 *     api: '/api/chat',
 *   });
 *
 *   return (
 *     <div className="flex flex-col h-screen">
 *       <div className="flex-1 overflow-y-auto p-4">
 *         {messages.map(message => (
 *           <div key={message.id} className={message.role === 'user' ? 'text-right' : 'text-left'}>
 *             <div className="inline-block p-3 rounded-lg bg-gray-100">
 *               {message.content}
 *             </div>
 *           </div>
 *         ))}
 *       </div>
 *       <form onSubmit={handleSubmit} className="p-4 border-t">
 *         <input
 *           value={input}
 *           onChange={handleInputChange}
 *           placeholder="Type your message..."
 *           disabled={isLoading}
 *           className="w-full p-2 border rounded"
 *         />
 *       </form>
 *     </div>
 *   );
 * }
 * ```
 */
