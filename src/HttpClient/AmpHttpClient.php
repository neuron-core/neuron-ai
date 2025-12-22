<?php

declare(strict_types=1);

namespace NeuronAI\HttpClient;

use Amp\ByteStream\ReadableResourceStream;
use Amp\Http\Client\Form;
use Amp\Http\Client\HttpClient;
use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\Request;
use Amp\Http\Client\Response;
use Amp\Http\Client\StreamedContent;
use NeuronAI\Exceptions\HttpException;
use Throwable;

use function is_array;
use function is_resource;
use function json_encode;
use function trim;

class AmpHttpClient implements HttpClientInterface
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

    /**
     * @throws \Amp\Http\Client\HttpException
     */
    protected function executeMultipart(HttpRequest $request): Response
    {
        $client = $this->client ?? HttpClientBuilder::buildDefault();

        $uri = $this->baseUri !== '' && $this->baseUri !== '0'
            ? trim($this->baseUri, '/') . '/' . trim($request->uri, '/')
            : $request->uri;

        $ampRequest = new Request($uri, $request->method->value);

        // Set headers
        foreach ([...$this->customHeaders, ...$request->headers] as $name => $value) {
            $ampRequest->setHeader((string)$name, $value);
        }

        // Create multipart form
        $form = new Form();

        foreach ($request->body as $name => $value) {
            // If it's already a properly formatted array with 'name' key
            if (is_array($value) && isset($value['name'])) {
                $name = $value['name'];
                $value = $value['contents'] ?? $value;
            }

            if (is_resource($value)) {
                $stream = new ReadableResourceStream($value);
                $content = StreamedContent::fromStream($stream);
                $form->addStream($name, $content);
            } elseif (is_array($value) && isset($value['contents'])) {
                if (is_resource($value['contents'])) {
                    $stream = new ReadableResourceStream($value['contents']);
                    $content = StreamedContent::fromStream($stream);
                    $form->addStream($name, $content, $value['filename'] ?? null);
                } else {
                    $form->addField($name, (string) $value['contents']);
                }
            } else {
                $form->addField($name, (string) $value);
            }
        }

        $ampRequest->setBody($form);

        return $client->request($ampRequest);
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
        // Check if this is a multipart request
        if (is_array($request->body) && $this->isMultipartData($request->body)) {
            return $this->executeMultipart($request);
        }

        $client = $this->client ?? HttpClientBuilder::buildDefault();

        $uri = $this->baseUri !== '' && $this->baseUri !== '0'
            ? trim($this->baseUri, '/') . '/' . trim($request->uri, '/')
            : $request->uri;

        $ampRequest = new Request($uri, $request->method->value);

        // Apply the configured timeout to the Amp Request
        $ampRequest->setTransferTimeout($this->timeout);
        $ampRequest->setInactivityTimeout($this->timeout);

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

    /**
     * Check if the body array contains multipart data (resources or nested arrays).
     *
     * @param array<string, mixed> $body
     */
    protected function isMultipartData(array $body): bool
    {
        foreach ($body as $value) {
            if (is_resource($value)) {
                return true;
            }

            if (is_array($value) && isset($value['contents']) && is_resource($value['contents'])) {
                return true;
            }
        }

        return false;
    }
}
