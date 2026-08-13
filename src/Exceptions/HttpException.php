<?php

declare(strict_types=1);

namespace NeuronAI\Exceptions;

use NeuronAI\HttpClient\HttpRequest;
use NeuronAI\HttpClient\HttpResponse;
use Throwable;

/**
 * Exception thrown when HTTP request fails.
 */
class HttpException extends NeuronException
{
    public function __construct(
        string $message,
        public readonly ?HttpRequest $request = null,
        public readonly ?HttpResponse $response = null,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function statusError(HttpRequest $request, HttpResponse $response, ?Throwable $previous = null): self
    {
        return new self(
            "HTTP {$response->statusCode} error during {$request->method->value} {$request->uri}: {$response->body}",
            $request,
            $response,
            $previous,
        );
    }

    public static function networkError(HttpRequest $request, string $reason, ?Throwable $previous = null): self
    {
        return new self(
            "Network error during {$request->method->value} {$request->uri}: {$reason}",
            $request,
            null,
            $previous,
        );
    }
}
