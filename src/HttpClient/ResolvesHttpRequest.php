<?php

declare(strict_types=1);

namespace NeuronAI\HttpClient;

use function preg_match;
use function rtrim;
use function ltrim;

/**
 * @internal
 */
trait ResolvesHttpRequest
{
    protected function resolveRequestUri(string $uri, string $baseUri): string
    {
        if ($baseUri === '' || preg_match('~^https?://~i', $uri) === 1) {
            return $uri;
        }

        return rtrim($baseUri, '/') . ($uri !== '' ? '/' . ltrim($uri, '/') : '');
    }
}
