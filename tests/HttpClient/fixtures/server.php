<?php

declare(strict_types=1);

/**
 * Router script for PHP's built-in server, used by the HTTP client tests.
 * Each endpoint fakes one behavior the HTTP clients must handle.
 */

$path = \parse_url($_SERVER['REQUEST_URI'], \PHP_URL_PATH);

switch ($path) {
    case '/json':
        \header('Content-Type: application/json');
        \header('X-Custom-Header: neuron');
        echo \json_encode(['status' => 'success']);
        break;

    case '/echo':
        \header('Content-Type: application/json');
        echo \json_encode([
            'method' => $_SERVER['REQUEST_METHOD'],
            'contentType' => $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '',
            'authorization' => $_SERVER['HTTP_AUTHORIZATION'] ?? '',
            'expect' => $_SERVER['HTTP_EXPECT'] ?? '',
            'xHook' => $_SERVER['HTTP_X_HOOK'] ?? '',
            'userAgent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
            'body' => \file_get_contents('php://input'),
        ]);
        break;

    case '/multipart':
        \header('Content-Type: application/json');
        echo \json_encode([
            'authorization' => $_SERVER['HTTP_AUTHORIZATION'] ?? '',
            'fields' => $_POST,
            'files' => \array_map(
                fn (array $file): array => [
                    'name' => $file['name'],
                    'type' => $file['type'],
                    'content' => \file_get_contents($file['tmp_name']),
                ],
                $_FILES
            ),
        ]);
        break;

    case '/error':
        \http_response_code(422);
        \header('Content-Type: application/json');
        echo \json_encode(['error' => 'invalid input']);
        break;

    case '/status':
        $status = (int) ($_GET['code'] ?? 200);
        \http_response_code($status);
        echo "status {$status}";
        break;

    case '/redirect':
        \http_response_code(302);
        \header('Location: /json');
        break;

    case '/headers':
        \header('Content-Type: application/json');
        echo \json_encode(\array_change_key_case(getallheaders(), \CASE_LOWER));
        break;

    case '/redirect-to-other-host':
        // Same server, different origin: localhost instead of 127.0.0.1.
        \http_response_code(302);
        \header("Location: http://localhost:{$_SERVER['SERVER_PORT']}/headers");
        break;

    case '/redirect-loop':
        \http_response_code(302);
        \header('Location: /redirect-loop');
        break;

    case '/lines':
        // A line split across two flushes, then a last line with no newline.
        while (\ob_get_level() > 0) {
            \ob_end_flush();
        }
        foreach (["alpha\nbe", "ta\n", "gamma"] as $chunk) {
            echo $chunk;
            \flush();
            \usleep(20_000);
        }
        break;

    case '/large':
        for ($line = 0; $line < 10_000; $line++) {
            \printf("line-%05d\n", $line);
        }
        break;

    case '/truncated':
        // Promises more bytes than it sends, so the transfer fails midway.
        \header('Content-Length: 1000');
        echo 'partial';
        break;

    case '/sse':
        \header('Content-Type: text/event-stream');
        \header('Cache-Control: no-cache');

        while (\ob_get_level() > 0) {
            \ob_end_flush();
        }

        for ($i = 0; $i < 3; $i++) {
            if ($i > 0) {
                \usleep(250_000);
            }
            echo "data: chunk{$i}\n\n";
            \flush();
        }
        break;

    case '/delay':
        \usleep(100_000);
        echo 'done';
        break;

    case '/slow':
        \usleep(2_000_000);
        echo 'too late';
        break;

    default:
        \http_response_code(404);
        echo 'not found';
}
