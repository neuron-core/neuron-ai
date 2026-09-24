<?php

declare(strict_types=1);

/*
 * An HTTP/1.1 server that keeps connections open, which PHP's built-in server never does,
 * unless the request asks to close. Every response names the connection that carried it
 * (the client's address) and echoes the `value` query parameter. It serves GET requests
 * only: a request body would be read as the next request.
 */

$server = \stream_socket_server("tcp://127.0.0.1:{$argv[1]}", $errorCode, $errorMessage);
if ($server === false) {
    \fwrite(\STDERR, $errorMessage . \PHP_EOL);
    exit(1);
}

$connections = [];
$buffers = [];

while (true) {
    $readable = [$server, ...\array_values($connections)];
    $writable = $except = null;
    if (\stream_select($readable, $writable, $except, null) === false) {
        exit(1);
    }

    foreach ($readable as $socket) {
        if ($socket === $server) {
            $connection = \stream_socket_accept($server, 0, $peer);
            if ($connection !== false) {
                $connections[$peer] = $connection;
                $buffers[$peer] = '';
            }
            continue;
        }

        $peer = \array_search($socket, $connections, true);
        $chunk = \fread($socket, 8192);
        if ($chunk === '' || $chunk === false) {
            \fclose($socket);
            unset($connections[$peer], $buffers[$peer]);
            continue;
        }

        $buffers[$peer] .= $chunk;
        while (($end = \strpos($buffers[$peer], "\r\n\r\n")) !== false) {
            $head = \substr($buffers[$peer], 0, $end);
            $buffers[$peer] = \substr($buffers[$peer], $end + 4);

            [$requestLine] = \explode("\r\n", $head);
            \parse_str((string) \parse_url(\explode(' ', $requestLine)[1] ?? '/', \PHP_URL_QUERY), $query);
            $body = \json_encode(['connection' => $peer, 'value' => $query['value'] ?? null]);

            \fwrite($socket, "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nContent-Length: " . \strlen($body) . "\r\n\r\n{$body}");

            if (\preg_match('/^Connection:\s*close/im', $head) === 1) {
                \fclose($socket);
                unset($connections[$peer], $buffers[$peer]);
                break;
            }
        }
    }
}
