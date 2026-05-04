<?php

declare(strict_types=1);

namespace Flowline\Http;

/**
 * Framework-agnostic HTTP response DTO.
 *
 * Framework adapters convert this to their own response object:
 *
 *   // Laravel example
 *   return new JsonResponse($response->body, $response->statusCode);
 */
final class Response
{
    /**
     * @param int $statusCode HTTP status code
     * @param mixed $body Response body (will be JSON-encoded)
     * @param array<string, string> $headers Response headers
     */
    public function __construct(
        public readonly int $statusCode,
        public readonly mixed $body,
        public readonly array $headers = [],
    ) {}
}
