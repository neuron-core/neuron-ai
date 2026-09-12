<?php

declare(strict_types=1);

/**
 * Example application endpoint for the frontend integration suite.
 * Run with: php -S 127.0.0.1:8787 tests/Integration/Frontend/backend/router.php
 *
 * Protocol routes carry only protocol fields; test controls live under /_test.
 */

use NeuronAI\Agent\Adapters\AGUIAdapter;
use NeuronAI\Agent\Adapters\VercelAIAdapter;
use NeuronAI\Agent\Frontend\AGUIInputTranslator;
use NeuronAI\Agent\Frontend\VercelAIInputTranslator;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Exceptions\InputTranslationException;
use NeuronAI\Exceptions\WorkflowException;
use NeuronAI\Tests\Integration\Frontend\Stub\Fixture;
use NeuronAI\Workflow\Streaming\Adapter\SSEAdapter;

require __DIR__ . '/../../../../vendor/autoload.php';

$fixture = new Fixture(getenv('NEURON_FIXTURE_DB') ?: sys_get_temp_dir() . '/neuron-frontend-fixture.sqlite');

$method = $_SERVER['REQUEST_METHOD'];
$path = (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$payload = json_decode(file_get_contents('php://input') ?: '{}', true, flags: JSON_THROW_ON_ERROR);

function respondJson(int $status, array $body): void
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($body, JSON_THROW_ON_ERROR);
}

function badRequest(string $reason): never
{
    throw new InputTranslationException($reason);
}

/**
 * Send the protocol headers, then relay frames as they are produced. A failure
 * after this point is already on the wire as the adapter's own error frame.
 */
function streamFrames(Generator $frames, SSEAdapter $adapter): void
{
    foreach ($adapter->getHeaders() as $name => $value) {
        header("{$name}: {$value}");
    }
    try {
        foreach ($frames as $frame) {
            echo $frame;
            flush();
        }
    } catch (Throwable) {
    }
}

/**
 * @param array<string, mixed> $payload
 * @return array<string, mixed> the message the request ends with, or [] when there is none
 */
function lastMessage(array $payload): array
{
    $messages = $payload['messages'] ?? [];
    return $messages === [] ? [] : $messages[array_key_last($messages)];
}

/** @param array<string, mixed> $payload */
function agui(Fixture $fixture, array $payload): void
{
    $threadId = $payload['threadId'] ?? badRequest('AG-UI input requires threadId.');
    $last = lastMessage($payload);
    $translator = new AGUIInputTranslator();
    $agent = $fixture->agent($threadId, $translator->tools($payload));
    $adapter = new AGUIAdapter($threadId, $payload['runId'] ?? null, $payload['messages'] ?? [], $payload['state'] ?? []);
    $agent->setStreamAdapter($adapter);

    $continuation = ($payload['resume'] ?? []) !== [] || ($last['role'] ?? null) === 'tool';
    $frames = match (true) {
        $continuation => $agent->submitInputs($payload, $translator)->events(),
        ($last['role'] ?? null) === 'user' => $agent->stream(new UserMessage((string) $last['content'])),
        default => badRequest('AG-UI input must end with a user message or carry a continuation.'),
    };
    streamFrames($frames, $adapter);
}

/** @param array<string, mixed> $payload */
function vercel(Fixture $fixture, array $payload): void
{
    $threadId = $payload['id'] ?? badRequest('Vercel chat requests require the chat id.');
    $last = lastMessage($payload);
    $agent = $fixture->agent($threadId, $fixture->frontendTools());
    $adapter = match ($last['role'] ?? null) {
        'assistant' => new VercelAIAdapter($last['id'], $last['parts'] ?? []),
        'user' => new VercelAIAdapter(),
        default => badRequest('Vercel chat requests must end with a user or assistant message.'),
    };
    $agent->setStreamAdapter($adapter);

    $frames = $last['role'] === 'assistant'
        ? $agent->submitInputs($payload, new VercelAIInputTranslator())->events()
        : $agent->stream(new UserMessage(implode('', array_map(
            fn (array $part): string => $part['type'] === 'text' ? (string) $part['text'] : '',
            $last['parts'] ?? [],
        ))));
    streamFrames($frames, $adapter);
}

try {
    if ($method === 'GET' && $path === '/_test/health') {
        respondJson(200, ['ok' => true]);
    } elseif ($method === 'POST' && $path === '/_test/threads') {
        $fixture->registerThread((string) $payload['threadId'], (string) $payload['scenario']);
        respondJson(201, ['threadId' => $payload['threadId']]);
    } elseif ($method === 'GET' && str_starts_with($path, '/_test/threads/')) {
        respondJson(200, $fixture->observe(rawurldecode(substr($path, strlen('/_test/threads/')))));
    } elseif ($method === 'POST' && $path === '/agui') {
        agui($fixture, $payload);
    } elseif ($method === 'POST' && $path === '/vercel') {
        vercel($fixture, $payload);
    } else {
        respondJson(404, ['error' => "No route for {$method} {$path}"]);
    }
} catch (Throwable $error) {
    if (headers_sent()) {
        exit;
    }
    $status = match (true) {
        $error instanceof InputTranslationException => 400,
        $error instanceof WorkflowException => 409,
        default => 500,
    };
    respondJson($status, ['error' => $error->getMessage(), 'type' => $error::class]);
}
