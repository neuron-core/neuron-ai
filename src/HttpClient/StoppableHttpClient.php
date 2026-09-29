<?php

declare(strict_types=1);

namespace NeuronAI\HttpClient;

use Closure;

/**
 * Lets the application stop a streamed answer mid-flight, for a "stop
 * generating" button. The provider keeps the text streamed so far as a
 * complete answer whose stop reason is STOP_REASON; a stream that ends early
 * without being stopped here is a cut connection, and the provider throws.
 */
class StoppableHttpClient implements HttpClientInterface
{
    /** The stop reason of an answer the application stopped. */
    public const STOP_REASON = 'stopped';

    /**
     * @param Closure(): bool $shouldStop asked before every event the provider reads, so keep it cheap
     */
    public function __construct(
        protected HttpClientInterface $client,
        protected Closure $shouldStop,
    ) {
    }

    public function request(HttpRequest $request): HttpResponse
    {
        return $this->client->request($request);
    }

    public function stream(HttpRequest $request): StreamInterface
    {
        return new StoppableStream($this->client->stream($request), $this->shouldStop);
    }
}
