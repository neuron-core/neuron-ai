<?php

declare(strict_types=1);

/*
 * A scriptable MCP server speaking newline-delimited JSON-RPC over stdio. Its options
 * arrive as a JSON object in the first argument:
 *  - descriptionBytes: length of the `echo` tool's description in tools/list
 *  - stderrBytes: bytes written to stderr before each response
 *  - notify: send a notification before each response
 *  - exitAfter: exit after answering this many requests
 *  - partialLineOnExit: when exiting after answering, leave the start of a message unterminated
 *  - exitBeforeAnswering: exit without answering the request with this ordinal
 *  - splitWrites: write each response in two parts, flushed apart
 *  - blankLines: surround each message with blank lines and end it with CRLF
 *  - batched: write the notification and the response in a single write
 * tools/call echoes its `value` argument and reports the server's process ID, the
 * arguments it was started with after the options, and NEURON_MCP_FIXTURE from its environment.
 */

$options = \json_decode($argv[1] ?? '{}', true) + [
    'descriptionBytes' => 16,
    'stderrBytes' => 0,
    'notify' => false,
    'exitAfter' => 0,
    'partialLineOnExit' => false,
    'exitBeforeAnswering' => 0,
    'splitWrites' => false,
    'blankLines' => false,
    'batched' => false,
];
$answered = 0;
$received = 0;

$frame = static fn (array $message): string => $options['blankLines']
    ? "\r\n" . \json_encode($message) . "\r\n\r\n"
    : \json_encode($message) . "\n";

while (($line = \fgets(\STDIN)) !== false) {
    $request = \json_decode($line, true);
    if (!isset($request['id'])) {
        continue;
    }

    if (++$received === $options['exitBeforeAnswering']) {
        exit(1);
    }

    \fwrite(\STDERR, \str_repeat('x', $options['stderrBytes']));

    $output = $options['notify'] || $options['batched']
        ? $frame(['jsonrpc' => '2.0', 'method' => 'notifications/message', 'params' => ['level' => 'info', 'data' => 'working']])
        : '';
    if (!$options['batched']) {
        \fwrite(\STDOUT, $output);
        $output = '';
    }

    $result = match ($request['method']) {
        'initialize' => ['protocolVersion' => '2025-11-25', 'capabilities' => new \stdClass(), 'serverInfo' => ['name' => 'fixture', 'version' => '1.0.0']],
        'tools/list' => ['tools' => [[
            'name' => 'echo',
            'description' => \str_repeat('d', $options['descriptionBytes']),
            'inputSchema' => ['type' => 'object', 'properties' => ['value' => ['type' => 'string']]],
        ]]],
        'tools/call' => [
            'content' => [['type' => 'text', 'text' => $request['params']['arguments']['value'] ?? '']],
            'server' => \getmypid(),
            'args' => \array_slice($argv, 2),
            'env' => \getenv('NEURON_MCP_FIXTURE') ?: null,
        ],
        default => new \stdClass(),
    };

    $output .= $frame(['jsonrpc' => '2.0', 'id' => $request['id'], 'result' => $result]);

    $exiting = ++$answered === $options['exitAfter'];
    if ($exiting && $options['partialLineOnExit']) {
        // Written with the response, so the client reads both at once.
        $output .= '{"jsonrpc":"2.0","method":';
    }

    if ($options['splitWrites']) {
        $half = \intdiv(\strlen($output), 2);
        \fwrite(\STDOUT, \substr($output, 0, $half));
        \fflush(\STDOUT);
        \usleep(20_000);
        $output = \substr($output, $half);
    }

    \fwrite(\STDOUT, $output);
    \fflush(\STDOUT);

    if ($exiting) {
        exit(0);
    }
}
