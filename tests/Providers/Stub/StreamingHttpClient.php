<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Providers\Stub;

use LogicException;
use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\HttpClient\HttpRequest;
use NeuronAI\HttpClient\HttpResponse;
use NeuronAI\HttpClient\StreamInterface;

/**
 * Answers every streamed request with the given stream.
 */
class StreamingHttpClient implements HttpClientInterface
{
    public function __construct(protected StreamInterface $stream)
    {
    }

    public function request(HttpRequest $request): HttpResponse
    {
        throw new LogicException('Only streamed requests are expected');
    }

    public function stream(HttpRequest $request): StreamInterface
    {
        return $this->stream;
    }
}
