<?php

declare(strict_types=1);

namespace Flowline\Http;

/**
 * Framework-agnostic HTTP request DTO.
 *
 * Framework adapters create this from their own request object:
 *
 *   // Laravel example
 *   new Request(
 *       method: $request->method(),
 *       headers: $request->headers->all(),
 *       body: $request->getContent(),
 *       query: $request->query->all(),
 *   );
 */
final class Request
{
    /**
     * @param string $method HTTP method (GET, PUT, POST)
     * @param array<string, string|list<string>> $headers Request headers
     * @param string $body Raw request body
     * @param array<string, string> $query Query parameters
     */
    public function __construct(
        public readonly string $method,
        public readonly array $headers,
        public readonly string $body,
        public readonly array $query = [],
    ) {}
}
