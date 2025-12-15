<?php

declare(strict_types=1);

namespace NeuronAI\HttpClient;

use NeuronAI\Exceptions\HttpException;

/**
 * Framework-agnostic HTTP client interface for AI provider communication.
 *
 * This interface abstracts HTTP operations to allow providers to work with
 * any HTTP client implementation (Guzzle, Amp, ReactPHP, etc.).
 *
 * Implementations can support both synchronous and asynchronous patterns
 * based on the execution context.
 */
interface HttpClientInterface
{
    /**
     * Send an HTTP request and return the response.
     *
     * This method should block until the response is received.
     * For async contexts, implementations should integrate with the
     * async runtime (Amp Fibers, ReactPHP event loop, etc.).
     *
     * @throws HttpException on request failure
     */
    public function request(HttpRequest $request): HttpResponse;

    /**
     * Send an HTTP request and return a stream for reading the response body.
     *
     * Used for Server-Sent Events (SSE) and other streaming responses.
     * The stream allows reading the response incrementally without buffering
     * the entire response in memory.
     *
     * @throws HttpException on request failure
     */
    public function stream(HttpRequest $request): StreamInterface;

    public function withBaseUri(string $baseUri): HttpClientInterface;

    /**
     * @param array<string, string> $headers
     */
    public function withHeaders(array $headers): HttpClientInterface;

    public function withTimeout(float $timeout): HttpClientInterface;
}
