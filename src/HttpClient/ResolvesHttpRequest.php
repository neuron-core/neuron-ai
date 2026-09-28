<?php

declare(strict_types=1);

namespace NeuronAI\HttpClient;

use function parse_url;
use function preg_match;
use function rtrim;
use function ltrim;
use function strtolower;

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

    /**
     * Whether a redirect target keeps the scheme, host and port of the request.
     * A relative target, such as a path, always does.
     */
    protected function isSameOrigin(string $uri, string $target): bool
    {
        $request = parse_url($uri);
        $redirect = parse_url($target);

        if ($request === false || $redirect === false) {
            return false;
        }

        if (!isset($redirect['host'])) {
            return true;
        }

        return $this->origin($redirect + ['scheme' => $request['scheme'] ?? '']) === $this->origin($request);
    }

    /**
     * @param array<string, int|string> $parts The parse_url() parts of an absolute URI
     */
    protected function origin(array $parts): string
    {
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);

        return $scheme . '://' . strtolower((string) ($parts['host'] ?? '')) . ':' . $port;
    }
}
