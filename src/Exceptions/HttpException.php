<?php

declare(strict_types=1);

namespace NeuronAI\Exceptions;

use Exception;
use NeuronAI\HttpClient\HttpRequest;
use NeuronAI\HttpClient\HttpResponse;
use Throwable;

/**
 * Exception thrown when HTTP request fails.
 */
class HttpException extends Exception
{
    public function __construct(
        string $message,
        public readonly ?HttpRequest $request = null,
        public readonly ?HttpResponse $response = null,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * Create an exception for network error.
     */
    public static function networkError(HttpRequest $request, Throwable $previous): self
    {
        return new self(
            "Network error during {$request->method->value} {$request->uri}: {$previous->getMessage()}",
            $request,
            null,
            $previous
        );
    }

    /**
     * Create an exception for HTTP error response.
     */
    public static function httpError(HttpRequest $request, HttpResponse $response): self
    {
        return new self(
            "HTTP {$response->statusCode} error during {$request->method->value} {$request->uri}",
            $request,
            $response
        );
    }
}
