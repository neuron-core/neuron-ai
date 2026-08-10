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
use NeuronAI\Chat\Messages\Stream\Adapters\AGUIAdapter;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Providers\Anthropic\Anthropic;
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
                config('services.anthropic.model'),
            )
        )
        ->addTool(
            CalculatorToolkit::make()
        );

    $adapter = new AGUIAdapter($request->threadId);

    // stream() returns a generator yielding the adapter's protocol lines.
    $stream = $agent->stream(new UserMessage($validated['message']), $adapter);

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
