<?php

declare(strict_types=1);

namespace NeuronAI\MCP;

use JsonException;
use NeuronAI\Exceptions\HttpException;
use NeuronAI\HttpClient\Curl\CurlHttpClient;
use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\HttpClient\HttpMethod;
use NeuronAI\HttpClient\HttpRequest;
use NeuronAI\HttpClient\HttpResponse;

use function array_map;
use function array_merge;
use function array_shift;
use function explode;
use function filter_var;
use function implode;
use function json_decode;
use function json_encode;
use function str_replace;
use function str_starts_with;
use function substr;
use function trim;

use const FILTER_VALIDATE_URL;
use const JSON_THROW_ON_ERROR;

class StreamableHttpTransport implements McpTransportInterface
{
    protected readonly HttpClientInterface $httpClient;
    protected ?string $sessionId = null;
    protected ?string $protocolVersion = null;
    protected ?HttpResponse $lastResponse = null;

    /**
     * Messages of the last response not received yet: an SSE response may carry
     * notifications and server requests before the response itself.
     *
     * @var array<int, array<string, mixed>>
     */
    protected array $pendingMessages = [];

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(
        protected array $config,
        ?HttpClientInterface $httpClient = null,
    ) {
        $this->httpClient = $httpClient ?? new CurlHttpClient();
    }

    /**
     * @throws McpException
     */
    public function connect(): void
    {
        if (!isset($this->config['url'])) {
            throw new McpException('URL is required for HTTP transport');
        }

        if (!filter_var($this->config['url'], FILTER_VALIDATE_URL)) {
            throw new McpException('Invalid URL format');
        }

        // No connection test: HTTP is stateless, the first request validates the endpoint
    }

    /**
     * @param array<string, mixed> $data
     * @throws McpException
     */
    public function send(array $data): void
    {
        if (!isset($this->config['url'])) {
            throw new McpException('URL is required for HTTP transport');
        }

        try {
            $headers = array_merge($this->getAuthHeaders(), [
                'Accept' => 'application/json, text/event-stream',
                'Content-Type' => 'application/json',
            ]);

            if ($this->sessionId !== null) {
                $headers['Mcp-Session-Id'] = $this->sessionId;
            }

            if ($this->protocolVersion !== null) {
                $headers['MCP-Protocol-Version'] = $this->protocolVersion;
            }

            $jsonData = json_encode($data, JSON_THROW_ON_ERROR);

            $response = $this->httpClient->request(new HttpRequest(
                method: HttpMethod::POST,
                uri: $this->config['url'],
                headers: $headers,
                body: $jsonData,
                timeout: (float) ($this->config['timeout'] ?? 30),
            ));

            $mcpSessionId = $response->header('Mcp-Session-Id');
            if ($mcpSessionId !== null) {
                $this->sessionId = $mcpSessionId;
            }

            $this->lastResponse = $response;
            $this->pendingMessages = [];

        } catch (HttpException $e) {
            // The server ended the session and processed nothing: the client must initialize a new one.
            if ($e->response?->statusCode === 404 && $this->sessionId !== null) {
                throw new McpSessionLostException('The MCP session has expired', $e->getCode(), $e);
            }

            if ($e->response?->statusCode === 401) {
                throw new McpException('Authentication failed: Invalid or expired token', $e->getCode(), $e);
            }

            if ($e->response?->statusCode === 403) {
                throw new McpException('Authorization failed: Insufficient permissions', $e->getCode(), $e);
            }

            throw new McpException('HTTP request failed: ' . $e->getMessage(), $e->getCode(), $e);
        } catch (JsonException $e) {
            throw new McpException('Failed to encode JSON: ' . $e->getMessage(), $e->getCode(), $e);
        }
    }

    /**
     * @return array<string, mixed>
     * @throws McpException
     */
    public function receive(): array
    {
        if ($this->lastResponse instanceof HttpResponse) {
            $this->pendingMessages = $this->decodeMessages($this->lastResponse->body);
            $this->lastResponse = null;
        }

        if ($this->pendingMessages === []) {
            throw new McpException('No response available. Call send() first.');
        }

        return array_shift($this->pendingMessages);
    }

    /**
     * @return array<int, array<string, mixed>>
     * @throws McpException
     */
    protected function decodeMessages(string $body): array
    {
        if ($body === '') {
            throw new McpException('Empty response body');
        }

        try {
            try {
                return [json_decode($body, true, 64, JSON_THROW_ON_ERROR)];
            } catch (JsonException) {
                // Streamable HTTP servers may answer with SSE framing instead of plain JSON
                return array_map(
                    fn (string $json): array => json_decode($json, true, 64, JSON_THROW_ON_ERROR),
                    $this->parseSSEResponse($body),
                );
            }
        } catch (JsonException $e) {
            throw new McpException('Invalid JSON response: ' . $e->getMessage(), $e->getCode(), $e);
        }
    }

    public function setProtocolVersion(string $version): void
    {
        $this->protocolVersion = $version;
    }

    public function disconnect(): void
    {
        // HTTP is stateless: nothing to close, just drop session state
        $this->sessionId = null;
        $this->protocolVersion = null;
        $this->lastResponse = null;
        $this->pendingMessages = [];
    }

    /**
     * @return array<string, string>
     */
    protected function getAuthHeaders(): array
    {
        $headers = $this->config['headers'] ?? [];

        if (isset($this->config['token'])) {
            $headers['Authorization'] = 'Bearer ' . $this->config['token'];
        }

        return $headers;
    }

    /**
     * Extract the JSON payloads of an SSE-framed response body, in order: one per event,
     * whose data lines join into it.
     *
     * @return array<int, string>
     * @throws McpException
     */
    protected function parseSSEResponse(string $sseResponse): array
    {
        $payloads = [];

        foreach (explode("\n\n", str_replace(["\r\n", "\r"], "\n", $sseResponse)) as $event) {
            $data = [];
            // Only data lines carry the payload: comments start with ':', and event, id and retry fields are ignored.
            foreach (explode("\n", $event) as $line) {
                if (str_starts_with($line, 'data:')) {
                    $data[] = trim(substr($line, 5));
                }
            }

            if ($data !== []) {
                $payloads[] = implode("\n", $data);
            }
        }

        if ($payloads === []) {
            throw new McpException('No JSON data found in SSE response');
        }

        return $payloads;
    }
}
