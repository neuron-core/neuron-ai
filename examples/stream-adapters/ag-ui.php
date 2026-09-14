<?php

declare(strict_types=1);

use NeuronAI\Agent\Adapters\AGUIAdapter;
use NeuronAI\Agent\Agent;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Providers\Anthropic\Anthropic;
use NeuronAI\Tools\Toolkits\Calculator\CalculatorToolkit;
use NeuronAI\Workflow\Streaming\SSEEncoder;

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

// The adapter turns Neuron's native chunks into AG-UI protocol events, so
// stream() yields one ProtocolEvent per wire event. In AG-UI the thread id
// comes from the client; a CLI demo can make one up.
$stream = $agent
    ->setStreamAdapter(new AGUIAdapter(threadId: \uniqid()))
    ->stream(new UserMessage('What is the square root of 144?'));

// SSE framing belongs to the HTTP edge: the encoder turns each event into a "data:" line.
foreach (SSEEncoder::encode($stream) as $line) {
    echo $line;
    \flush();
}
