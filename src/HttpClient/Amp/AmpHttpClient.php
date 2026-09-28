<?php

declare(strict_types=1);

namespace NeuronAI\HttpClient\Amp;

use Amp\ByteStream\ReadableResourceStream;
use Amp\Http\Client\BufferedContent;
use Amp\Http\Client\Form;
use Amp\Http\Client\HttpClient;
use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\HttpContent;
use Amp\Http\Client\Interceptor\SetRequestHeaderIfUnset;
use Amp\Http\Client\Request;
use Amp\Http\Client\Response;
use Amp\Http\Client\StreamedContent;
use NeuronAI\Exceptions\HttpException;
use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\HttpClient\HttpRequest;
use NeuronAI\HttpClient\HttpResponse;
use NeuronAI\HttpClient\MergesHttpHeaders;
use NeuronAI\HttpClient\ResolvesHttpRequest;
use NeuronAI\HttpClient\StreamInterface;
use Throwable;

use function basename;
use function getmypid;
use function is_array;
use function is_resource;
use function json_encode;
use function stream_get_meta_data;

use const JSON_THROW_ON_ERROR;

class AmpHttpClient implements HttpClientInterface
{
    use ResolvesHttpRequest;
    use MergesHttpHeaders;

    protected string $baseUri = '';

    protected ?HttpClient $client = null;

    /**
     * The process that created the client, and with it its connection pool.
     */
    protected ?int $clientProcessId = null;

    /**
     * Clients inherited from a parent process, never used again (see leaveInheritedConnections()).
     *
     * @var array<int, HttpClient>
     */
    protected array $inheritedClients = [];

    /**
     * @param array<string, string> $customHeaders
     */
    public function __construct(
        protected array $customHeaders = [],
        protected float $timeout = 120.0,
    ) {
    }

    public function request(HttpRequest $request): HttpResponse
    {
        try {
            $response = $this->buffer($this->execute($request));

            if ($response->statusCode >= 400) {
                throw HttpException::statusError($request, $response);
            }

            return $response;
        } catch (HttpException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw HttpException::networkError($request, $e->getMessage(), $e);
        }
    }

    /**
     * @throws \Amp\Http\Client\HttpException
     */
    protected function executeMultipart(HttpRequest $request): Response
    {
        $client = $this->getClient();

        $uri = $this->resolveRequestUri($request->uri, $this->baseUri);

        $ampRequest = new Request($uri, $request->method->value);

        // Set headers
        foreach ($this->mergeRequestHeaders($this->customHeaders, $request->headers) as $name => $value) {
            $ampRequest->setHeader((string)$name, $value);
        }

        // Create multipart form
        $form = new Form();

        foreach ($request->body as $name => $value) {
            // If it's already a properly formatted array with 'name' key
            if (is_array($value) && isset($value['name'])) {
                $name = $value['name'];
            }

            if (is_resource($value)) {
                $value = ['contents' => $value];
            }

            // A part is uploaded as a file, string contents included, as CurlHttpClient does
            if (is_array($value) && isset($value['contents'])) {
                $filename = $value['filename'] ?? $this->partFilename($value['contents'], $name);
                $form->addStream($name, $this->partContent($value), $filename);
            } else {
                $form->addField($name, (string) $value);
            }
        }

        $ampRequest->setBody($form);

        $ampRequest->setTransferTimeout($request->timeout ?? $this->timeout);
        $ampRequest->setInactivityTimeout($request->timeout ?? $this->timeout);

        return $client->request($ampRequest);
    }

    public function stream(HttpRequest $request): StreamInterface
    {
        try {
            $response = $this->execute($request);

            // The error body is read whole so the exception can report it, as with the other clients
            if ($response->getStatus() >= 400) {
                throw HttpException::statusError($request, $this->buffer($response));
            }

            return new AmpStream($response->getBody());
        } catch (HttpException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw HttpException::networkError($request, $e->getMessage(), $e);
        }
    }

    public function withBaseUri(string $baseUri): static
    {
        $this->baseUri = $baseUri;
        return $this;
    }

    public function withHeaders(array $headers): static
    {
        $this->customHeaders = $this->mergeRequestHeaders($this->customHeaders, $headers);
        return $this;
    }

    public function withTimeout(float $timeout): static
    {
        $this->timeout = $timeout;
        return $this;
    }

    protected function getClient(): HttpClient
    {
        $this->leaveInheritedConnections();

        return $this->client ??= (new HttpClientBuilder())
            ->intercept(new SetRequestHeaderIfUnset('User-Agent', HttpClientInterface::USER_AGENT))
            ->build();
    }

    /**
     * As in CurlHttpClient, a forked child opens its own connections instead of writing to
     * its parent's, and keeps the inherited client referenced so they stay open.
     */
    protected function leaveInheritedConnections(): void
    {
        $processId = (int) getmypid();

        if ($this->client instanceof HttpClient && $this->clientProcessId !== $processId) {
            $this->inheritedClients[] = $this->client;
            $this->client = null;
        }

        $this->clientProcessId = $processId;
    }

    /**
     * @throws \Amp\Http\Client\HttpException
     */
    protected function execute(HttpRequest $request): Response
    {
        // Check if this is a multipart request
        if ($request->isMultipart()) {
            return $this->executeMultipart($request);
        }

        $client = $this->getClient();

        $uri = $this->resolveRequestUri($request->uri, $this->baseUri);

        $ampRequest = new Request($uri, $request->method->value);

        // Apply the configured timeout to the Amp Request
        $ampRequest->setTransferTimeout($request->timeout ?? $this->timeout);
        $ampRequest->setInactivityTimeout($request->timeout ?? $this->timeout);

        // Set headers
        foreach ($this->mergeRequestHeaders($this->customHeaders, $request->headers) as $name => $value) {
            $ampRequest->setHeader((string)$name, $value);
        }

        // Set body if present
        if ($request->body !== null) {
            if (is_array($request->body)) {
                if (!$ampRequest->hasHeader('Content-Type')) {
                    $ampRequest->setHeader('Content-Type', 'application/json');
                }
                $ampRequest->setBody(json_encode($request->body, JSON_THROW_ON_ERROR));
            } else {
                $ampRequest->setBody($request->body);
            }
        }

        // Execute request and get streaming body
        return $client->request($ampRequest);
    }

    protected function buffer(Response $response): HttpResponse
    {
        return new HttpResponse(
            statusCode: $response->getStatus(),
            body: $response->getBody()->buffer(),
            headers: $response->getHeaders(),
        );
    }

    /**
     * @param array<string, mixed> $part
     */
    protected function partContent(array $part): HttpContent
    {
        $contentType = $part['headers']['Content-Type'] ?? 'application/octet-stream';

        return is_resource($part['contents'])
            ? StreamedContent::fromStream(new ReadableResourceStream($part['contents']), contentType: $contentType)
            : BufferedContent::fromString((string) $part['contents'], $contentType);
    }

    /**
     * As in CurlHttpClient, APIs infer the file format from the extension, so
     * a part without a filename is named after the file it reads.
     */
    protected function partFilename(mixed $contents, string $fieldName): string
    {
        if (!is_resource($contents)) {
            return $fieldName;
        }

        $meta = stream_get_meta_data($contents);

        return isset($meta['uri']) ? basename($meta['uri']) : $fieldName;
    }
}
