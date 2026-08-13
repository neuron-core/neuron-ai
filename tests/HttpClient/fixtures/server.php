<?php

declare(strict_types=1);

/**
 * Router script for PHP's built-in server, used by CurlHttpClientTest.
 * Each endpoint fakes one behavior the CurlHttpClient must handle.
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
            'body' => \file_get_contents('php://input'),
        ]);
        break;

    case '/multipart':
        \header('Content-Type: application/json');
        echo \json_encode([
            'fields' => $_POST,
            'files' => \array_map(
                fn (array $file): array => [
                    'name' => $file['name'],
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

    case '/redirect':
        \http_response_code(302);
        \header('Location: /json');
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

    case '/slow':
        \usleep(2_000_000);
        echo 'too late';
        break;

    default:
        \http_response_code(404);
        echo 'not found';
}
