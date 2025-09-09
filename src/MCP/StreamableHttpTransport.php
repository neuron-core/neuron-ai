<?php

declare(strict_types=1);

namespace NeuronAI\MCP;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Psr7\Request;
use Psr\Http\Message\StreamInterface;

class StreamableHttpTransport implements McpTransportInterface
{
    private readonly Client $httpClient;
    private ?string $sessionId = null;
    private ?string $lastEventId = null;
    private mixed $lastResponse = null;

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
                $headers['X-Session-ID'] = $this->sessionId;
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

            if ($response->getStatusCode() !== 200) {
                throw new McpException('HTTP request failed with status: ' . $response->getStatusCode());
            }

            // Extract session ID from response headers if present
            if ($response->hasHeader('X-Session-ID')) {
                $this->sessionId = $response->getHeader('X-Session-ID')[0];
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
        if ($this->lastResponse === null) {
            throw new McpException('No response available. Call send() first.');
        }

        try {
            $responseBody = $this->lastResponse->getBody()->getContents();
            $this->lastResponse = null; // Clear the stored response

            if ($responseBody === '') {
                throw new McpException('Empty response body');
            }

            // Parse SSE format to extract JSON data
            $jsonData = $this->parseSSEResponse($responseBody);
            
            return \json_decode($jsonData, true, 512, \JSON_THROW_ON_ERROR);
            
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
        $this->lastEventId = null;
        $this->lastResponse = null;
    }

    /**
     * Get authentication headers based on configuration
     *
     * @return array<string, string>
     */
    private function getAuthHeaders(): array
    {
        $headers = [];

        // Add Bearer token if provided
        if (isset($this->config['token'])) {
            $headers['Authorization'] = 'Bearer ' . $this->config['token'];
        }

        // Add Origin header for security
        if (isset($this->config['origin'])) {
            $headers['Origin'] = $this->config['origin'];
        }

        return $headers;
    }

    /**
     * Parse Server-Sent Events response to extract JSON data
     *
     * @param string $sseResponse
     * @return string
     * @throws McpException
     */
    private function parseSSEResponse(string $sseResponse): string
    {
        $lines = \explode("\n", $sseResponse);
        $jsonData = '';
        
        foreach ($lines as $line) {
            $line = \trim($line);
            
            // Skip empty lines and comments
            if ($line === '' || \str_starts_with($line, ':')) {
                continue;
            }
            
            // Extract data from SSE format
            if (\str_starts_with($line, 'data: ')) {
                $jsonData = \substr($line, 6);
                break; // We found the JSON data, we can stop
            }
        }
        
        if ($jsonData === '') {
            throw new McpException('No JSON data found in SSE response');
        }
        
        return $jsonData;
    }
}
