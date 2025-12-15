<?php

declare(strict_types=1);

namespace NeuronAI\HttpClient;

use Amp\Http\Client\HttpClient;
use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\Request;
use Amp\Http\Client\Response;
use NeuronAI\Exceptions\HttpException;
use Throwable;

use function is_array;
use function json_encode;
use function trim;

final class AmpHttpClient implements HttpClientInterface
{
    protected string $baseUri = '';

    protected ?HttpClient $client = null;

    /**
     * @param array<string, string> $customHeaders
     */
    public function __construct(
        protected readonly array $customHeaders = [],
        protected readonly float $timeout = 30.0,
    ) {
    }

    public function request(HttpRequest $request): HttpResponse
    {
        try {
            $response = $this->execute($request);

            return new HttpResponse(
                statusCode: $response->getStatus(),
                body: $response->getBody()->buffer(),
                headers: $response->getHeaders(),
            );
        } catch (Throwable $e) {
            throw HttpException::networkError($request, $e);
        }
    }

    public function stream(HttpRequest $request): StreamInterface
    {
        try {
            $response = $this->execute($request);

            return new AmpStream($response->getBody());
        } catch (Throwable $e) {
            throw HttpException::networkError($request, $e);
        }
    }

    public function withBaseUri(string $baseUri): AmpHttpClient
    {
        $new = new self($this->customHeaders, $this->timeout);
        $new->baseUri = $baseUri;
        return $new;
    }

    public function withHeaders(array $headers): AmpHttpClient
    {
        $new = new self([...$this->customHeaders, ...$headers], $this->timeout);
        $new->baseUri = $this->baseUri;
        return $new;
    }

    public function withTimeout(float $timeout): AmpHttpClient
    {
        $new = new self($this->customHeaders, $timeout);
        $new->baseUri = $this->baseUri;
        return $new;
    }

    /**
     * @throws \Amp\Http\Client\HttpException
     */
    protected function execute(HttpRequest $request): Response
    {
        $client = $this->client ?? HttpClientBuilder::buildDefault();

        $uri = $this->baseUri !== '' && $this->baseUri !== '0'
            ? trim($this->baseUri, '/') . '/' . trim($request->uri, '/')
            : $request->uri;

        $ampRequest = new Request($uri, $request->method);

        // Set headers
        foreach ([...$this->customHeaders, ...$request->headers] as $name => $value) {
            $ampRequest->setHeader((string)$name, $value);
        }

        // Set body if present
        if ($request->body !== null) {
            if (is_array($request->body)) {
                $ampRequest->setHeader('Content-Type', 'application/json');
                $ampRequest->setBody(json_encode($request->body));
            } else {
                $ampRequest->setBody($request->body);
            }
        }

        // Execute request and get streaming body
        return $client->request($ampRequest);
    }
}
