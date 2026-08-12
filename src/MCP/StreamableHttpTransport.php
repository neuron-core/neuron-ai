<?php

declare(strict_types=1);

namespace NeuronAI\MCP;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Psr7\Request;
use Psr\Http\Message\ResponseInterface;
use JsonException;

use function array_merge;
use function explode;
use function filter_var;
use function json_decode;
use function json_encode;
use function str_starts_with;
use function substr;
use function trim;

use const FILTER_VALIDATE_URL;
use const JSON_THROW_ON_ERROR;

class StreamableHttpTransport implements McpTransportInterface
{
    protected readonly Client $httpClient;
    protected ?string $sessionId = null;
    protected ?ResponseInterface $lastResponse = null;

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(protected array $config)
    {
        $this->httpClient = new Client([
            'timeout' => $config['timeout'] ?? 30,
            'headers' => [
                'Accept' => 'application/json, text/event-stream',
                'Content-Type' => 'application/json',
                'User-Agent' => 'neuron-ai/1.0.0',
            ],
        ]);
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
                'Content-Type' => 'application/json',
            ]);

            if ($this->sessionId !== null) {
                $headers['Mcp-Session-Id'] = $this->sessionId;
            }

            $jsonData = json_encode($data, JSON_THROW_ON_ERROR);

            $request = new Request('POST', $this->config['url'], $headers, $jsonData);
            $response = $this->httpClient->send($request);

            if ($response->getStatusCode() === 401) {
                throw new McpException('Authentication failed: Invalid or expired token');
            }

            if ($response->getStatusCode() === 403) {
                throw new McpException('Authorization failed: Insufficient permissions');
            }

            if ($response->hasHeader('Mcp-Session-Id')) {
                $this->sessionId = $response->getHeader('Mcp-Session-Id')[0];
            }

            $this->lastResponse = $response;

        } catch (GuzzleException $e) {
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
        if (!$this->lastResponse instanceof ResponseInterface) {
            throw new McpException('No response available. Call send() first.');
        }

        try {
            $response = (string) $this->lastResponse->getBody();
            $this->lastResponse = null;

            if ($response === '') {
                throw new McpException('Empty response body');
            }

            try {
                return json_decode($response, true, 64, JSON_THROW_ON_ERROR);
            } catch (JsonException $e) {
                // Streamable HTTP servers may answer with SSE framing instead of plain JSON
                $json = $this->parseSSEResponse($response);
                return json_decode($json, true, 64, JSON_THROW_ON_ERROR);
            }

        } catch (JsonException $e) {
            throw new McpException('Invalid JSON response: ' . $e->getMessage(), $e->getCode(), $e);
        }
    }

    public function disconnect(): void
    {
        // HTTP is stateless: nothing to close, just drop session state
        $this->sessionId = null;
        $this->lastResponse = null;
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
     * Extract the JSON payload from an SSE-framed response body.
     *
     * @throws McpException
     */
    protected function parseSSEResponse(string $sseResponse): string
    {
        $lines = explode("\n", $sseResponse);

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            // Lines starting with ':' are SSE comments
            if (str_starts_with($line, ':')) {
                continue;
            }

            if (str_starts_with($line, 'data: ')) {
                return substr($line, 6);
            }
        }

        throw new McpException('No JSON data found in SSE response');
    }
}
