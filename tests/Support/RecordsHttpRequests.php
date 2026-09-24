<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Support;

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use NeuronAI\HttpClient\Guzzle\GuzzleHttpClient;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

use function array_map;

/** An HTTP client answering with queued responses and recording every request it sends. */
trait RecordsHttpRequests
{
    /** @var array<int, array{request: RequestInterface, response: ResponseInterface|null, error: mixed, options: array<mixed>}> */
    protected array $sentRequests = [];

    protected function recordingClient(ResponseInterface ...$responses): GuzzleHttpClient
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->sentRequests));

        return new GuzzleHttpClient(handler: $stack);
    }

    /**
     * @return list<string> Each sent request as "METHOD uri".
     */
    protected function sentTargets(): array
    {
        return array_map(
            static fn (array $entry): string => $entry['request']->getMethod().' '.$entry['request']->getUri(),
            $this->sentRequests,
        );
    }
}
