<?php

declare(strict_types=1);

namespace NeuronAI\Tests\RAG\VectorStore\Stub;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use NeuronAI\Tests\Support\RecordsHttpRequests;
use Psr\Http\Message\RequestInterface;

use function json_decode;
use function json_encode;
use function parse_str;

use const JSON_THROW_ON_ERROR;

/**
 * Records the HTTP traffic of a vector store, whether it talks through the
 * framework HTTP client or through a vendor SDK built on a PSR-18 client.
 */
trait RecordsVectorStoreRequests
{
    use RecordsHttpRequests;

    /**
     * A PSR-18 Guzzle client for vendor SDKs, answering with queued responses.
     */
    protected function recordingPsrClient(Response ...$responses): Client
    {
        return new Client(['handler' => $this->recordingHandler(...$responses)]);
    }

    /**
     * A Guzzle handler stack for SDKs that build their own Guzzle client.
     */
    protected function recordingHandler(Response ...$responses): HandlerStack
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->sentRequests));

        return $stack;
    }

    /**
     * @param array<mixed> $body
     * @param array<string, string> $headers
     */
    protected function jsonResponse(array $body = [], int $status = 200, array $headers = []): Response
    {
        return new Response($status, ['Content-Type' => 'application/json', ...$headers], json_encode($body, JSON_THROW_ON_ERROR));
    }

    protected function sentRequest(int $index): RequestInterface
    {
        return $this->sentRequests[$index]['request'];
    }

    /**
     * @return array<mixed>
     */
    protected function sentJson(int $index): array
    {
        return json_decode((string) $this->sentRequest($index)->getBody(), true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<string, mixed>
     */
    protected function sentQuery(int $index): array
    {
        parse_str($this->sentRequest($index)->getUri()->getQuery(), $query);

        return $query;
    }
}
