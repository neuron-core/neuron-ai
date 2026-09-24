<?php

declare(strict_types=1);

/**
 * Example: Laravel AG-UI endpoint
 *
 * An AG-UI client such as CopilotKit posts a RunAgentInput body (threadId,
 * runId, messages, state, tools). This endpoint answers the last user message
 * and streams the run back as AG-UI events over SSE. Continuing a paused run
 * and registering the client's tool catalog are covered in
 * src/Agent/Frontend/README.md.
 */

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use NeuronAI\Agent\Adapters\AGUIAdapter;
use NeuronAI\Agent\Agent;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Providers\Anthropic\Anthropic;
use NeuronAI\Tools\Toolkits\Calculator\CalculatorToolkit;
use NeuronAI\Workflow\Streaming\SSEEncoder;

// routes/api.php
Route::post('/agui', function (Request $request) {
    $input = $request->validate([
        'threadId' => 'required|string',
        'runId' => 'nullable|string',
        'messages' => 'required|array|min:1',
        'state' => 'nullable|array',
    ]);

    $last = $input['messages'][\array_key_last($input['messages'])];
    if (($last['role'] ?? null) !== 'user') {
        abort(422, 'AG-UI input must end with a user message.');
    }

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

    // Seeding the adapter with the client's messages and state keeps the
    // frontend snapshot in sync without echoing what it already holds.
    $adapter = new AGUIAdapter(
        threadId: $input['threadId'],
        runId: $input['runId'] ?? null,
        messages: $input['messages'],
        state: $input['state'] ?? [],
    );

    // stream() yields AG-UI ProtocolEvents; SSE framing happens at the HTTP edge.
    // The request runs one segment, so the adapter built here is the one it streams with.
    $stream = $agent->setStreamAdapter(fn (): AGUIAdapter => $adapter)->stream(new UserMessage((string) $last['content']));

    return response()->stream(
        function () use ($stream) {
            foreach (SSEEncoder::encode($stream) as $line) {
                echo $line;
                \ob_flush();
                \flush();
            }
        },
        200,
        $adapter->getHeaders()
    );
});
