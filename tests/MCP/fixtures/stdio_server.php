<?php

declare(strict_types=1);

/*
 * A scriptable MCP server speaking newline-delimited JSON-RPC over stdio. Its options
 * arrive as a JSON object in the first argument:
 *  - descriptionBytes: length of the `echo` tool's description in tools/list
 *  - stderrBytes: bytes written to stderr before each response
 *  - notify: send a notification before each response
 *  - exitAfter: exit after answering this many requests
 * tools/call echoes its `value` argument and reports the server's process ID.
 */

$options = \json_decode($argv[1] ?? '{}', true) + [
    'descriptionBytes' => 16,
    'stderrBytes' => 0,
    'notify' => false,
    'exitAfter' => 0,
];
$answered = 0;

while (($line = \fgets(\STDIN)) !== false) {
    $request = \json_decode($line, true);
    if (!isset($request['id'])) {
        continue;
    }

    \fwrite(\STDERR, \str_repeat('x', $options['stderrBytes']));

    if ($options['notify']) {
        \fwrite(\STDOUT, \json_encode(['jsonrpc' => '2.0', 'method' => 'notifications/message', 'params' => ['level' => 'info', 'data' => 'working']]) . "\n");
    }

    $result = match ($request['method']) {
        'initialize' => ['protocolVersion' => '2025-11-25', 'capabilities' => new \stdClass(), 'serverInfo' => ['name' => 'fixture', 'version' => '1.0.0']],
        'tools/list' => ['tools' => [[
            'name' => 'echo',
            'description' => \str_repeat('d', $options['descriptionBytes']),
            'inputSchema' => ['type' => 'object', 'properties' => ['value' => ['type' => 'string']]],
        ]]],
        'tools/call' => ['content' => [['type' => 'text', 'text' => $request['params']['arguments']['value'] ?? '']], 'server' => \getmypid()],
        default => new \stdClass(),
    };

    \fwrite(\STDOUT, \json_encode(['jsonrpc' => '2.0', 'id' => $request['id'], 'result' => $result]) . "\n");
    \fflush(\STDOUT);

    if (++$answered === $options['exitAfter']) {
        exit(0);
    }
}
