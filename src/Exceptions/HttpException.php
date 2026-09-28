<?php

declare(strict_types=1);

namespace NeuronAI\Exceptions;

use NeuronAI\HttpClient\HttpRequest;
use NeuronAI\HttpClient\HttpResponse;
use Throwable;

use function preg_replace;

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
            "HTTP {$response->statusCode} error during {$request->method->value} " . self::withoutUserInfo($request->uri) . ": {$response->body}",
            $request,
            $response,
            $previous,
        );
    }

    public static function networkError(HttpRequest $request, string $reason, ?Throwable $previous = null): self
    {
        return new self(
            "Network error during {$request->method->value} " . self::withoutUserInfo($request->uri) . ": {$reason}",
            $request,
            null,
            $previous,
        );
    }

    /**
     * Messages reach logs and error trackers: the user:password@ part of a URL must not.
     */
    protected static function withoutUserInfo(string $uri): string
    {
        return (string) preg_replace('~^([a-z][a-z0-9+.-]*://)[^/?#@]*@~i', '$1', $uri);
    }
}
