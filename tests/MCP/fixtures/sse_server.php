<?php

declare(strict_types=1);

/*
 * Router script for PHP's built-in server speaking MCP's legacy HTTP+SSE transport.
 * It needs one worker for the event stream plus one per concurrent POST.
 *
 * GET .../sse opens the event stream. Query parameters script the server:
 *  - status: answer with this HTTP status and no stream
 *  - session: announce this Mcp-Session-Id header
 *  - endpoint: announce this POST endpoint instead of /messages?session=<id>
 *  - close: end the stream right after announcing the endpoint
 *  - bare: send answers as data-only events, which SSE types as 'message'
 * POST /messages?session=<id> queues a JSON-RPC message for the stream of that session.
 * The stream answers each request, after some traffic a client must skip (comments,
 * other events, invalid JSON, payloads that are not JSON-RPC). tools/call echoes its
 * `value` argument and reports the headers of the POST and of the stream request.
 */

$spool = \sys_get_temp_dir() . '/neuron-mcp-sse-' . $_SERVER['SERVER_PORT'];
if (!\is_dir($spool)) {
    @\mkdir($spool);
}

$path = \parse_url($_SERVER['REQUEST_URI'], \PHP_URL_PATH);
$session = \preg_replace('/[^A-Za-z0-9-]/', '', (string) ($_GET['session'] ?? ''));
$headers = \array_change_key_case(getallheaders(), \CASE_LOWER);

if ($path === '/messages' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $message = \json_decode((string) \file_get_contents('php://input'), true);
    \file_put_contents("{$spool}/{$session}.jsonl", \json_encode(['message' => $message, 'headers' => $headers]) . "\n", \FILE_APPEND | \LOCK_EX);
    \http_response_code(202);
    return;
}

if (!\str_ends_with($path, '/sse')) {
    \http_response_code(404);
    return;
}

if (isset($_GET['status'])) {
    \http_response_code((int) $_GET['status']);
    return;
}

if ($session === '') {
    $session = \bin2hex(\random_bytes(8));
}

\header('Content-Type: text/event-stream');
\header('Cache-Control: no-cache');
if (isset($_GET['session'])) {
    \header("Mcp-Session-Id: {$session}");
}
while (\ob_get_level() > 0) {
    \ob_end_flush();
}

$emit = static function (string $frame): void {
    echo $frame;
    \flush();
};

$emit(": stream opened\n\n");
$emit("event: endpoint\ndata: " . ($_GET['endpoint'] ?? "/messages?session={$session}") . "\n\n");

if (isset($_GET['close'])) {
    return;
}

$inbox = "{$spool}/{$session}.jsonl";
$consumed = 0;
$deadline = \microtime(true) + 10;

while (\microtime(true) < $deadline) {
    $lines = \is_file($inbox) ? \file($inbox, \FILE_IGNORE_NEW_LINES) : [];

    foreach (\array_slice($lines, $consumed) as $line) {
        $consumed++;
        ['message' => $request, 'headers' => $postHeaders] = \json_decode($line, true);
        if (!isset($request['id'])) {
            continue;
        }

        $result = match ($request['method']) {
            'initialize' => ['protocolVersion' => '2024-11-05', 'capabilities' => new \stdClass(), 'serverInfo' => ['name' => 'sse-fixture', 'version' => '1.0.0']],
            'tools/list' => ['tools' => [['name' => 'echo', 'inputSchema' => ['type' => 'object', 'properties' => ['value' => ['type' => 'string']]]]]],
            'tools/call' => [
                'content' => [['type' => 'text', 'text' => $request['params']['arguments']['value'] ?? '']],
                'postHeaders' => $postHeaders,
                'streamHeaders' => $headers,
            ],
            default => new \stdClass(),
        };

        $emit("event: ping\ndata: {}\n\n");
        $emit(": comment\n\n");
        $emit("event: message\ndata: not json\n\n");
        $emit("event: message\ndata: {\"hello\":\"not json-rpc\"}\n\n");
        $emit((isset($_GET['bare']) ? '' : "event: message\n") . 'data: ' . \json_encode(['jsonrpc' => '2.0', 'id' => $request['id'], 'result' => $result]) . "\n\n");
    }

    // Writing is also how PHP notices a client that went away.
    $emit(": heartbeat\n\n");
    \usleep(20_000);
}
