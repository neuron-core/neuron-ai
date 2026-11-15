<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Providers\Stub;

use LogicException;
use NeuronAI\Exceptions\HttpException;
use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\HttpClient\HttpRequest;
use NeuronAI\HttpClient\HttpResponse;
use NeuronAI\HttpClient\StreamInterface;

use function array_shift;

/**
 * Answers buffered requests with queued responses and records every request.
 * Like the built-in clients, it throws HttpException for statuses >= 400.
 */
class QueuedHttpClient implements HttpClientInterface
{
    /** @var list<HttpRequest> */
    public array $requests = [];

    /** @var list<HttpResponse> */
    protected array $responses;

    public function __construct(HttpResponse ...$responses)
    {
        $this->responses = $responses;
    }

    public function request(HttpRequest $request): HttpResponse
    {
        $this->requests[] = $request;
        $response = array_shift($this->responses) ?? throw new LogicException('No response queued');

        if ($response->statusCode >= 400) {
            throw HttpException::statusError($request, $response);
        }

        return $response;
    }

    public function stream(HttpRequest $request): StreamInterface
    {
        throw new LogicException('Only buffered requests are expected');
    }
}
