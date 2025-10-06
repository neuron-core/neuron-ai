<?php

declare(strict_types=1);

namespace NeuronAI\MCP;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

/**
 * SSE HTTP Transport for MCP
 *
 * This transport handles Server-Sent Events (SSE) connections.
 * It uses a synchronous blocking approach compatible with NeuronAI's interface.
 */
class SseHttpTransport implements McpTransportInterface
{
    protected readonly Client $httpClient;
    protected ?string $sessionId = null;
    protected ?string $postEndpointUrl = null;

    /**
     * @var resource|null
     */
    protected $sseStream;

    protected string $sseBuffer = '';
    protected bool $connected = false;

    /**
     * Create a new SseHttpTransport with the given configuration
     *
     * @param array<string, mixed> $config
     */
    public function __construct(protected array $config)
    {
        $this->httpClient = new Client([
            'timeout' => $config['timeout'] ?? 30,
            'verify' => $config['verify'] ?? true,
        ]);
    }

    /**
     * Connect to the MCP HTTP+SSE server
     *
     * @throws McpException
     */
    public function connect(): void
    {
        if ($this->connected) {
            return;
        }

        if (!isset($this->config['url'])) {
            throw new McpException('URL is required for SSE HTTP transport');
        }

        // Validate URL format
        if (!\filter_var($this->config['url'], \FILTER_VALIDATE_URL)) {
            throw new McpException('Invalid URL format');
        }

        try {
            $headers = $this->getAuthHeaders();

            if ($this->sessionId !== null) {
                $headers['Mcp-Session-Id'] = $this->sessionId;
            }

            // Add Accept header for SSE
            $headers['Accept'] = 'text/event-stream';

            // Build header string for stream context
            $headerString = $this->buildHeaderString($headers);

            // Open SSE connection using stream
            $context = \stream_context_create([
                'http' => [
                    'method' => 'GET',
                    'header' => $headerString,
                    'timeout' => $this->config['timeout'] ?? 30,
                ],
                'ssl' => [
                    'verify_peer' => $this->config['verify'] ?? true,
                    'verify_peer_name' => $this->config['verify'] ?? true,
                ]
            ]);

            $this->sseStream = @\fopen($this->config['url'], 'r', false, $context);

            if ($this->sseStream === false) {
                throw new McpException('Failed to open SSE connection to: ' . $this->config['url']);
            }

            // Set non-blocking mode for reading
            \stream_set_blocking($this->sseStream, false);

            // Extract session ID from response headers if present
            $meta = \stream_get_meta_data($this->sseStream);
            if (isset($meta['wrapper_data']) && \is_array($meta['wrapper_data'])) {
                foreach ($meta['wrapper_data'] as $header) {
                    // Check status code
                    if (\stripos((string) $header, 'HTTP/') === 0 && \in_array(\preg_match('/HTTP\/\d\.\d\s+200/', (string) $header), [0, false], true)) {
                        $this->cleanup();
                        throw new McpException('SSE connection failed: ' . $header);
                    }
                    if (\stripos((string) $header, 'Mcp-Session-Id:') === 0) {
                        $this->sessionId = \trim(\substr((string) $header, 15));
                    }
                }
            }

            // Wait for the 'endpoint' event from SSE stream
            $this->waitForEndpoint();

            $this->connected = true;

        } catch (GuzzleException $e) {
            $this->cleanup();
            throw new McpException('HTTP connection failed: ' . $e->getMessage());
        }
    }

    /**
     * Wait for the endpoint event from SSE stream
     *
     * @throws McpException
     */
    protected function waitForEndpoint(): void
    {
        $timeout = \microtime(true) + 10; // 10 second timeout
        $endpointReceived = false;

        while (!$endpointReceived && \microtime(true) < $timeout) {
            if ($this->sseStream === null) {
                throw new McpException('SSE stream closed while waiting for endpoint');
            }

            // Read data from stream
            $data = \fread($this->sseStream, 8192);

            if ($data !== false && $data !== '') {
                $this->sseBuffer .= $data;

                // Process complete SSE events (delimited by \n\n)
                while (($pos = \strpos($this->sseBuffer, "\n\n")) !== false) {
                    $eventBlock = \substr($this->sseBuffer, 0, $pos);
                    $this->sseBuffer = \substr($this->sseBuffer, $pos + 2);

                    $parsed = $this->parseSseEvent($eventBlock);

                    if ($parsed['event'] === 'endpoint' && $parsed['data'] !== '') {
                        $this->postEndpointUrl = $this->resolveEndpointUrl($this->config['url'], $parsed['data']);
                        $endpointReceived = true;
                        break;
                    }
                }
            }

            if (!$endpointReceived) {
                \usleep(10000); // 10ms sleep to prevent busy waiting
            }
        }

        if (!$endpointReceived) {
            throw new McpException('Timeout waiting for endpoint event from server');
        }
    }

    /**
     * Parse a single SSE event block
     *
     * @return array{event: string, data: string, id: ?string}
     */
    protected function parseSseEvent(string $eventBlock): array
    {
        $event = 'message';
        $data = '';
        $id = null;

        foreach (\explode("\n", $eventBlock) as $line) {
            $line = \trim($line);
            if ($line === '') {
                continue;
            }
            if (\str_starts_with($line, ':')) {
                continue;
            }

            if (\str_starts_with($line, 'event:')) {
                $event = \trim(\substr($line, 6));
            } elseif (\str_starts_with($line, 'data:')) {
                $data .= \trim(\substr($line, 5));
            } elseif (\str_starts_with($line, 'id:')) {
                $id = \trim(\substr($line, 3));
            }
        }

        return ['event' => $event, 'data' => $data, 'id' => $id];
    }

    /**
     * Send a JSON-RPC request to the MCP HTTP server via POST
     *
     * @param array<string, mixed> $data
     * @throws McpException
     */
    public function send(array $data): void
    {
        if (!$this->connected) {
            throw new McpException('Not connected to server. Call connect() first.');
        }

        if ($this->postEndpointUrl === null) {
            throw new McpException('POST endpoint not available');
        }

        try {
            $headers = \array_merge($this->getAuthHeaders(), [
                'Content-Type' => 'application/json',
            ]);

            if ($this->sessionId !== null) {
                $headers['Mcp-Session-Id'] = $this->sessionId;
            }

            $jsonData = \json_encode($data, \JSON_THROW_ON_ERROR);

            // Send POST request to the endpoint URL
            $response = $this->httpClient->post($this->postEndpointUrl, [
                'headers' => $headers,
                'body' => $jsonData,
            ]);

            $statusCode = $response->getStatusCode();

            // For SSE-based MCP, POST typically returns 202 Accepted
            // The actual response comes via the SSE stream
            if ($statusCode !== 202 && $statusCode !== 200) {
                $body = (string) $response->getBody();
                throw new McpException("POST request failed with status {$statusCode}: " . ($body !== '' && $body !== '0' ? $body : '(no body)'));
            }

        } catch (GuzzleException $e) {
            throw new McpException('HTTP POST failed: ' . $e->getMessage());
        } catch (\JsonException $e) {
            throw new McpException('Failed to encode JSON: ' . $e->getMessage());
        }
    }

    /**
     * Receive a response from the SSE stream
     *
     * @return array<string, mixed>
     * @throws McpException
     */
    public function receive(): array
    {
        if (!$this->connected) {
            throw new McpException('Not connected to server. Call connect() first.');
        }

        if ($this->sseStream === null) {
            throw new McpException('SSE stream is not available');
        }

        $timeout = \microtime(true) + ($this->config['timeout'] ?? 30);
        $received = false;
        $response = null;

        while (!$received && \microtime(true) < $timeout) {
            // Read data from SSE stream
            $data = \fread($this->sseStream, 8192);

            if ($data === false) {
                if (\feof($this->sseStream)) {
                    throw new McpException('SSE stream closed by server');
                }
            } elseif ($data !== '') {
                $this->sseBuffer .= $data;

                // Process complete SSE events
                while (($pos = \strpos($this->sseBuffer, "\n\n")) !== false) {
                    $eventBlock = \substr($this->sseBuffer, 0, $pos);
                    $this->sseBuffer = \substr($this->sseBuffer, $pos + 2);

                    $parsed = $this->parseSseEvent($eventBlock);

                    // Only process 'message' events for JSON-RPC responses
                    if ($parsed['event'] === 'message' && $parsed['data'] !== '') {
                        try {
                            $message = \json_decode($parsed['data'], true, 512, \JSON_THROW_ON_ERROR);

                            // Check if it's a valid JSON-RPC message with an ID (response)
                            if (isset($message['jsonrpc']) && $message['jsonrpc'] === '2.0') {
                                $response = $message;
                                $received = true;
                                break;
                            }
                        } catch (\JsonException) {
                            // Ignore invalid JSON, continue reading
                        }
                    }
                }
            }

            if (!$received) {
                \usleep(10000); // 10ms sleep to prevent busy waiting
            }
        }

        if (!$received || $response === null) {
            throw new McpException('Timeout waiting for response from server');
        }

        return $response;
    }

    /**
     * Disconnect from the HTTP server
     */
    public function disconnect(): void
    {
        $this->cleanup();
    }

    /**
     * Clean up resources
     */
    protected function cleanup(): void
    {
        $this->connected = false;

        if ($this->sseStream !== null) {
            \fclose($this->sseStream);
            $this->sseStream = null;
        }

        $this->sseBuffer = '';
        $this->postEndpointUrl = null;
        $this->sessionId = null;
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
     * Build header string for stream context
     *
     * @param array<string, string> $headers
     */
    protected function buildHeaderString(array $headers): string
    {
        $headerLines = [];
        foreach ($headers as $key => $value) {
            $headerLines[] = "$key: $value";
        }
        return \implode("\r\n", $headerLines) . "\r\n";
    }

    /**
     * Resolve endpoint URL from relative path
     */
    protected function resolveEndpointUrl(string $base, string $relative): string
    {
        // If relative is absolute URL, return it
        if (\str_contains($relative, '://') || \str_starts_with($relative, '//')) {
            return $relative;
        }

        $baseParts = \parse_url($base);
        if ($baseParts === false) {
            throw new McpException('Invalid base URL');
        }

        // Build the authority part (scheme://host:port)
        $authority = ($baseParts['scheme'] ?? 'https') . '://';

        if (isset($baseParts['user'])) {
            $authority .= $baseParts['user'];
            if (isset($baseParts['pass'])) {
                $authority .= ':' . $baseParts['pass'];
            }
            $authority .= '@';
        }

        $authority .= $baseParts['host'] ?? '';

        if (isset($baseParts['port'])) {
            $authority .= ':' . $baseParts['port'];
        }

        // Resolve path
        if (\str_starts_with($relative, '/')) {
            // Absolute path relative to authority
            return $authority . $relative;
        }
        // Relative path to base path's directory
        $basePath = $baseParts['path'] ?? '/';
        $lastSlashPos = \strrpos($basePath, '/');
        $baseDir = $lastSlashPos === false || $lastSlashPos === 0 ? '/' : \substr($basePath, 0, $lastSlashPos + 1);
        return $authority . $baseDir . $relative;
    }
}
