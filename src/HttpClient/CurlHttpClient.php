<?php

declare(strict_types=1);

namespace NeuronAI\HttpClient;

use CurlHandle;
use CurlShareHandle;
use CURLStringFile;
use NeuronAI\Exceptions\HttpException;

use function basename;
use function curl_error;
use function curl_exec;
use function curl_init;
use function curl_multi_add_handle;
use function curl_multi_init;
use function curl_reset;
use function curl_setopt_array;
use function curl_share_init;
use function curl_share_setopt;
use function is_array;
use function is_resource;
use function json_encode;
use function stream_get_contents;
use function stream_get_meta_data;
use function strtolower;
use function trim;

use const CURL_LOCK_DATA_CONNECT;
use const CURL_LOCK_DATA_DNS;
use const CURL_LOCK_DATA_SSL_SESSION;
use const CURLOPT_CONNECTTIMEOUT_MS;
use const CURLOPT_CUSTOMREQUEST;
use const CURLOPT_ENCODING;
use const CURLOPT_FOLLOWLOCATION;
use const CURLOPT_HEADERFUNCTION;
use const CURLOPT_HTTPHEADER;
use const CURLOPT_MAXREDIRS;
use const CURLOPT_POSTFIELDS;
use const CURLOPT_RETURNTRANSFER;
use const CURLOPT_SHARE;
use const CURLOPT_TIMEOUT_MS;
use const CURLOPT_URL;
use const CURLOPT_WRITEFUNCTION;
use const CURLSHOPT_SHARE;

/**
 * Default HTTP client built on ext-curl. Dependency-free.
 *
 * stream() drives a curl multi handle so response bytes are surfaced
 * as they arrive — live SSE delivery is guaranteed, not client-dependent.
 */
class CurlHttpClient implements HttpClientInterface
{
    protected string $baseUri = '';

    protected ?CurlHandle $handle = null;

    protected ?CurlShareHandle $shareHandle = null;

    /**
     * @var array<int, callable(HttpRequest): HttpRequest>
     */
    protected array $requestHooks = [];

    /**
     * @var array<int, callable(HttpResponse, HttpRequest): void>
     */
    protected array $responseHooks = [];

    /**
     * @param array<string, string> $customHeaders
     * @param array<int, mixed> $curlOptions Raw CURLOPT_* overrides (proxies, custom CA bundles, ...)
     */
    public function __construct(
        protected array $customHeaders = [],
        protected float $timeout = 60.0,
        protected float $connectTimeout = 10.0,
        protected array $curlOptions = [],
    ) {
    }

    public function request(HttpRequest $request): HttpResponse
    {
        $request = $this->applyRequestHooks($request);
        $handle = $this->reusableHandle();

        $headers = new CurlHeaderCollector();
        $options = $this->buildOptions($request) + [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADERFUNCTION => fn (CurlHandle $curlHandle, string $line): int => $headers->ingestLine($line),
        ];

        curl_setopt_array($handle, $options);

        $body = curl_exec($handle);

        if ($body === false) {
            throw HttpException::networkError($request, curl_error($handle));
        }

        $response = new HttpResponse(
            statusCode: $headers->getStatusCode(),
            body: (string) $body,
            headers: $headers->getHeaders(),
        );

        $this->fireResponseHooks($response, $request);

        if ($response->statusCode >= 400) {
            throw HttpException::statusError($request, $response);
        }

        return $response;
    }

    public function stream(HttpRequest $request): StreamInterface
    {
        $request = $this->applyRequestHooks($request);

        $handle = curl_init();
        $multiHandle = curl_multi_init();
        $stream = new CurlStream($multiHandle, $handle, $request);

        $options = $this->buildOptions($request) + [
            CURLOPT_WRITEFUNCTION => fn (CurlHandle $curlHandle, string $data): int => $stream->write($data),
            CURLOPT_HEADERFUNCTION => fn (CurlHandle $curlHandle, string $line): int => $stream->writeHeader($line),
        ];

        curl_setopt_array($handle, $options);
        curl_multi_add_handle($multiHandle, $handle);

        $stream->awaitHeaders();

        if ($stream->getStatusCode() >= 400) {
            $response = new HttpResponse(
                statusCode: $stream->getStatusCode(),
                body: $stream->drain(),
                headers: $stream->getHeaders(),
            );
            $stream->close();

            $this->fireResponseHooks($response, $request);

            throw HttpException::statusError($request, $response);
        }

        // The body is a live stream, so response hooks see status and headers only.
        $this->fireResponseHooks(
            new HttpResponse(
                statusCode: $stream->getStatusCode(),
                body: '',
                headers: $stream->getHeaders(),
            ),
            $request,
        );

        return $stream;
    }

    /**
     * Register a hook that observes the outgoing request and may return
     * a modified one (dynamic auth headers, signing, logging). Hooks run
     * in registration order.
     *
     * @param callable(HttpRequest): HttpRequest $hook
     */
    public function onRequest(callable $hook): static
    {
        $this->requestHooks[] = $hook;
        return $this;
    }

    /**
     * Register a hook that observes responses (logging, metrics), including
     * error responses before they are thrown as HttpException. Observation
     * only — for retry or caching semantics wrap the client behind
     * HttpClientInterface instead. For stream() the body is a live stream,
     * so hooks receive it empty (except on error, where it is drained).
     *
     * @param callable(HttpResponse, HttpRequest): void $hook
     */
    public function onResponse(callable $hook): static
    {
        $this->responseHooks[] = $hook;
        return $this;
    }

    protected function applyRequestHooks(HttpRequest $request): HttpRequest
    {
        foreach ($this->requestHooks as $hook) {
            $request = $hook($request);
        }

        return $request;
    }

    protected function fireResponseHooks(HttpResponse $response, HttpRequest $request): void
    {
        foreach ($this->responseHooks as $hook) {
            $hook($response, $request);
        }
    }

    public function withBaseUri(string $baseUri): static
    {
        $this->baseUri = trim($baseUri, '/');
        return $this;
    }

    /**
     * @param array<string, string> $headers
     */
    public function withHeaders(array $headers): static
    {
        $this->customHeaders = [...$this->customHeaders, ...$headers];
        return $this;
    }

    public function withTimeout(float $timeout): static
    {
        $this->timeout = $timeout;
        return $this;
    }

    /**
     * Reuse one handle across requests so keep-alive connections survive.
     */
    protected function reusableHandle(): CurlHandle
    {
        if ($this->handle instanceof CurlHandle) {
            curl_reset($this->handle);
            return $this->handle;
        }

        return $this->handle = curl_init();
    }

    /**
     * Connections, TLS sessions, and DNS results shared across all of this
     * client's handles, so streamed requests reuse connections just like
     * the reusable request() handle does.
     */
    protected function shareHandle(): CurlShareHandle
    {
        if (!$this->shareHandle instanceof CurlShareHandle) {
            $this->shareHandle = curl_share_init();
            curl_share_setopt($this->shareHandle, CURLSHOPT_SHARE, CURL_LOCK_DATA_DNS);
            curl_share_setopt($this->shareHandle, CURLSHOPT_SHARE, CURL_LOCK_DATA_SSL_SESSION);
            curl_share_setopt($this->shareHandle, CURLSHOPT_SHARE, CURL_LOCK_DATA_CONNECT);
        }

        return $this->shareHandle;
    }

    /**
     * @return array<int, mixed>
     */
    protected function buildOptions(HttpRequest $request): array
    {
        $headers = [...$this->customHeaders, ...$request->headers];

        $options = [
            CURLOPT_URL => $this->resolveUri($request),
            CURLOPT_CUSTOMREQUEST => $request->method->value,
            CURLOPT_CONNECTTIMEOUT_MS => (int) ($this->connectTimeout * 1000),
            CURLOPT_TIMEOUT_MS => (int) ($this->timeout * 1000),
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_ENCODING => '',
            CURLOPT_SHARE => $this->shareHandle(),
        ];

        if ($request->body !== null) {
            if (is_array($request->body)) {
                if ($request->isMultipart()) {
                    // curl generates the multipart boundary and Content-Type itself.
                    $options[CURLOPT_POSTFIELDS] = $this->buildMultipartFields($request->body);
                } else {
                    $options[CURLOPT_POSTFIELDS] = json_encode($request->body);
                    if (!$this->hasHeader($headers, 'Content-Type')) {
                        $headers['Content-Type'] = 'application/json';
                    }
                }
            } else {
                $options[CURLOPT_POSTFIELDS] = $request->body;
            }
        }

        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = "{$name}: {$value}";
        }
        // Suppress "Expect: 100-continue" — some API gateways (e.g. Google Vertex) reject it.
        $headerLines[] = 'Expect:';

        $options[CURLOPT_HTTPHEADER] = $headerLines;

        // User-supplied CURLOPT_* overrides win over the defaults above.
        return $this->curlOptions + $options;
    }

    protected function resolveUri(HttpRequest $request): string
    {
        return $this->baseUri !== ''
            ? $this->baseUri . ($request->uri !== '' ? '/' . trim($request->uri, '/') : '')
            : $request->uri;
    }

    /**
     * @param array<string, string> $headers
     */
    protected function hasHeader(array $headers, string $name): bool
    {
        $name = strtolower($name);

        foreach ($headers as $headerName => $value) {
            if (strtolower($headerName) === $name) {
                return true;
            }
        }

        return false;
    }

    /**
     * Convert the framework's multipart body shape into curl POSTFIELDS.
     *
     * @param array<string, mixed> $body
     * @return array<string, string|CURLStringFile>
     */
    protected function buildMultipartFields(array $body): array
    {
        $fields = [];

        foreach ($body as $name => $value) {
            if (is_resource($value)) {
                $fields[$name] = $this->fileFromResource($value, $name);
                continue;
            }

            if (is_array($value) && isset($value['contents'])) {
                $filename = $value['filename'] ?? null;
                $mime = $value['headers']['Content-Type'] ?? 'application/octet-stream';

                $fields[$name] = is_resource($value['contents'])
                    ? $this->fileFromResource($value['contents'], $name, $filename, $mime)
                    : new CURLStringFile((string) $value['contents'], $filename ?? $name, $mime);
                continue;
            }

            $fields[$name] = (string) $value;
        }

        return $fields;
    }

    /**
     * APIs infer the file format from the extension, so the upload filename
     * must come from the underlying file when the resource points to one.
     *
     * @param resource $resource
     */
    protected function fileFromResource(
        mixed $resource,
        string $fieldName,
        ?string $filename = null,
        string $mime = 'application/octet-stream',
    ): CURLStringFile {
        if ($filename === null) {
            $meta = stream_get_meta_data($resource);
            $filename = isset($meta['uri']) ? basename($meta['uri']) : $fieldName;
        }

        return new CURLStringFile((string) stream_get_contents($resource), $filename, $mime);
    }
}
