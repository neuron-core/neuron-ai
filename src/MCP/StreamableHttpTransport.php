<?php

declare(strict_types=1);

namespace NeuronAI\MCP;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Psr7\Request;
use Psr\Http\Message\ResponseInterface;

class StreamableHttpTransport implements McpTransportInterface
{
    protected readonly Client $httpClient;
    protected ?string $sessionId = null;
    protected ?ResponseInterface $lastResponse = null;

    /**
     * Create a new StreamableHttpTransport with the given configuration
     *
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
     * Connect to the MCP HTTP server
     *
     * @throws McpException
     */
    public function connect(): void
    {
        if (!isset($this->config['url'])) {
            throw new McpException('URL is required for HTTP transport');
        }

        // Validate URL format
        if (!\filter_var($this->config['url'], \FILTER_VALIDATE_URL)) {
            throw new McpException('Invalid URL format');
        }

        // For HTTP transport, no explicit connection test is needed
        // The connection will be validated during the first request
    }

    /**
     * Send a JSON-RPC request to the MCP HTTP server
     *
     * @param array<string, mixed> $data
     * @throws McpException
     */
    public function send(array $data): void
    {
        if (!isset($this->config['url'])) {
            throw new McpException('URL is required for HTTP transport');
        }

        try {
            $headers = \array_merge($this->getAuthHeaders(), [
                'Content-Type' => 'application/json',
            ]);

            // Add session ID if available
            if ($this->sessionId !== null) {
                $headers['Mcp-Session-Id'] = $this->sessionId;
            }

            $jsonData = \json_encode($data, \JSON_THROW_ON_ERROR);

            $request = new Request('POST', $this->config['url'], $headers, $jsonData);
            $response = $this->httpClient->send($request);

            if ($response->getStatusCode() === 401) {
                throw new McpException('Authentication failed: Invalid or expired token');
            }

            if ($response->getStatusCode() === 403) {
                throw new McpException('Authorization failed: Insufficient permissions');
            }

            // Extract session ID from response headers if present
            if ($response->hasHeader('Mcp-Session-Id')) {
                $this->sessionId = $response->getHeader('Mcp-Session-Id')[0];
            }

            // Store the response for the receive() method
            $this->lastResponse = $response;

        } catch (GuzzleException $e) {
            throw new McpException('HTTP request failed: ' . $e->getMessage());
        } catch (\JsonException $e) {
            throw new McpException('Failed to encode JSON: ' . $e->getMessage());
        }
    }

    /**
     * Receive a response from the MCP HTTP server
     *
     * @return array<string, mixed>
     * @throws McpException
     */
    public function receive(): array
    {
        if (!$this->lastResponse instanceof ResponseInterface) {
            throw new McpException('No response available. Call send() first.');
        }

        try {
            $response = $this->lastResponse->getBody()->getContents();
            $this->lastResponse = null; // Clear the stored response

            if ($response === '') {
                throw new McpException('Empty response body');
            }

            try {
                return \json_decode($response, true, 512, \JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                // If the response from the server is not a valid JSON
                // try to parse the SSE format to extract JSON data
                $json = $this->parseSSEResponse($response);
                return \json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
            }

        } catch (\JsonException $e) {
            throw new McpException('Invalid JSON response: ' . $e->getMessage());
        }
    }

    /**
     * Disconnect from the HTTP server
     */
    public function disconnect(): void
    {
        // HTTP connections are stateless, no explicit disconnect needed
        $this->sessionId = null;
        $this->lastResponse = null;
    }

    /**
     * Get authentication headers based on configuration
     *
     * @return array<string, string>
     */
    protected function getAuthHeaders(): array
    {
        $headers = $this->config['headers'] ?? [];

        // Add Bearer token if provided
        if (isset($this->config['token'])) {
            $headers['Authorization'] = 'Bearer ' . $this->config['token'];
        }

        return $headers;
    }

    /**
     * Parse Server-Sent Events response to extract JSON data
     *
     * @throws McpException
     */
    protected function parseSSEResponse(string $sseResponse): string
    {
        $lines = \explode("\n", $sseResponse);

        foreach ($lines as $line) {
            $line = \trim($line);
            // Skip empty lines and comments
            if ($line === '') {
                continue;
            }
            if (\str_starts_with($line, ':')) {
                continue;
            }

            // Extract data from SSE format
            if (\str_starts_with($line, 'data: ')) {
                return \substr($line, 6);
            }
        }

        throw new McpException('No JSON data found in SSE response');
    }
}
