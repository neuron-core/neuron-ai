<?php

declare(strict_types=1);

namespace NeuronAI\Tests\MCP\Stub;

use LogicException;
use NeuronAI\Exceptions\HttpException;
use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\HttpClient\HttpRequest;
use NeuronAI\HttpClient\HttpResponse;
use NeuronAI\HttpClient\StreamInterface;
use Throwable;

use function array_shift;

/**
 * Answers buffered requests with queued replies and records every request. Like the
 * built-in clients, it throws an HttpException carrying the response for status >= 400.
 */
class ScriptedHttpClient implements HttpClientInterface
{
    /**
     * @var list<HttpRequest>
     */
    public array $requests = [];

    /**
     * @var list<HttpResponse|Throwable>
     */
    protected array $replies;

    public function __construct(HttpResponse|Throwable ...$replies)
    {
        $this->replies = $replies;
    }

    public function request(HttpRequest $request): HttpResponse
    {
        $this->requests[] = $request;

        $reply = array_shift($this->replies) ?? throw new LogicException("No reply queued for {$request->uri}");

        if ($reply instanceof Throwable) {
            throw $reply;
        }

        if ($reply->statusCode >= 400) {
            throw HttpException::statusError($request, $reply);
        }

        return $reply;
    }

    public function stream(HttpRequest $request): StreamInterface
    {
        throw new LogicException('MCP transports send buffered requests only');
    }
}
