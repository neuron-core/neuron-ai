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

        // Test connection by making a basic request
        try {
            $response = $this->httpClient->get($this->config['url'], [
                'headers' => $this->getAuthHeaders(),
            ]);

            if ($response->getStatusCode() !== 200) {
                throw new McpException('HTTP connection failed with status: ' . $response->getStatusCode());
            }
        } catch (GuzzleException $e) {
            throw new McpException('Failed to connect to HTTP server: ' . $e->getMessage());
        }
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

        } catch (GuzzleException $e) {
            throw new McpException('HTTP request failed: ' . $e->getMessage());
        } catch (\JsonException $e) {
            throw new McpException('Failed to encode JSON: ' . $e->getMessage());
        }
    }

    /**
     * Receive a response from the MCP HTTP server using Server-Sent Events
     *
     * @return array<string, mixed>
     * @throws McpException
     */
    public function receive(): array
    {
        if (!isset($this->config['url'])) {
            throw new McpException('URL is required for HTTP transport');
        }

        try {
            $headers = \array_merge($this->getAuthHeaders(), [
                'Accept' => 'text/event-stream',
            ]);

            // Add session ID if available
            if ($this->sessionId !== null) {
                $headers['X-Session-ID'] = $this->sessionId;
            }

            // Add Last-Event-ID for resumability
            if ($this->lastEventId !== null) {
                $headers['Last-Event-ID'] = $this->lastEventId;
            }

            $response = $this->httpClient->get($this->config['url'], [
                'headers' => $headers,
                'stream' => true,
            ]);

            if ($response->getStatusCode() !== 200) {
                throw new McpException('SSE connection failed with status: ' . $response->getStatusCode());
            }

            $body = $response->getBody();
            $timeout = \time() + ($this->config['timeout'] ?? 30);

            $eventData = '';
            $eventId = null;

            while (!$body->eof() && \time() < $timeout) {
                $line = $this->readLine($body);
                if ($line === null) {
                    continue;
                }

                $line = \trim($line);

                // Empty line indicates end of event
                if ($line === '') {
                    if ($eventData !== '') {
                        // Update last event ID for resumability
                        if ($eventId !== null) {
                            $this->lastEventId = $eventId;
                        }

                        // Parse and return the JSON-RPC message
                        try {
                            return \json_decode($eventData, true, 512, \JSON_THROW_ON_ERROR);
                        } catch (\JsonException $e) {
                            throw new McpException('Invalid JSON in SSE data: ' . $e->getMessage());
                        }
                    }
                    continue;
                }

                // Parse SSE field
                if (\str_starts_with($line, 'data: ')) {
                    $eventData = \substr($line, 6);
                } elseif (\str_starts_with($line, 'id: ')) {
                    $eventId = \substr($line, 4);
                } elseif (\str_starts_with($line, 'event: ')) {
                    // Event type - we can ignore for JSON-RPC
                    continue;
                } elseif (\str_starts_with($line, 'retry: ')) {
                    // Retry timeout - we can ignore
                    continue;
                } elseif (\str_starts_with($line, ':')) {
                    // Comment - ignore
                    continue;
                }
            }

            throw new McpException('Timeout waiting for SSE response');

        } catch (GuzzleException $e) {
            throw new McpException('SSE request failed: ' . $e->getMessage());
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
     * Read a line from the stream
     */
    private function readLine(StreamInterface $stream): ?string
    {
        $line = '';
        while (!$stream->eof()) {
            $char = $stream->read(1);
            if ($char === '') {
                \usleep(10000); // 10ms delay to prevent CPU spinning
                continue;
            }
            if ($char === "\n") {
                return $line;
            }
            if ($char !== "\r") {
                $line .= $char;
            }
        }

        return $line !== '' ? $line : null;
    }
}
