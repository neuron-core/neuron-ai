<?php

declare(strict_types=1);

/*
 * A raw socket server for what PHP's built-in server cannot send: an informational
 * (1xx) header block before the final response. Every request gets "103 Early Hints",
 * then, a moment later, a "500 Internal Server Error" whose body is "boom".
 *
 * Usage: php early_hints_server.php <port>
 */

$server = \stream_socket_server('tcp://127.0.0.1:' . (int) ($argv[1] ?? 0), $errorCode, $errorMessage);
if ($server === false) {
    \fwrite(\STDERR, "{$errorMessage}\n");
    exit(1);
}

while ($connection = @\stream_socket_accept($server, -1)) {
    $requestLine = \fgets($connection);

    // Skip the rest of the request head: the readiness probe connects and leaves without one.
    do {
        $line = \fgets($connection);
    } while (\is_string($line) && \trim($line) !== '');

    if (\is_string($requestLine) && $requestLine !== '') {
        \fwrite($connection, "HTTP/1.1 103 Early Hints\r\nLink: </style.css>; rel=preload\r\n\r\n");
        // Hints arrive ahead of the response they announce, as from a CDN: the client reads them alone.
        \fflush($connection);
        \usleep(200_000);
        \fwrite($connection, "HTTP/1.1 500 Internal Server Error\r\nContent-Type: text/plain\r\nContent-Length: 4\r\nConnection: close\r\n\r\nboom");
    }

    \fclose($connection);
}
