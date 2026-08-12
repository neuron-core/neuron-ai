<?php

declare(strict_types=1);

namespace NeuronAI\MCP;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use JsonException;

use function array_merge;
use function explode;
use function fclose;
use function feof;
use function filter_var;
use function fopen;
use function fread;
use function implode;
use function in_array;
use function is_array;
use function json_decode;
use function json_encode;
use function microtime;
use function parse_url;
use function preg_match;
use function str_contains;
use function str_starts_with;
use function stream_context_create;
use function stream_get_meta_data;
use function stream_set_blocking;
use function stripos;
use function strpos;
use function strrpos;
use function substr;
use function trim;
use function usleep;
use function strlen;
use function strpbrk;

use const FILTER_VALIDATE_URL;
use const JSON_THROW_ON_ERROR;

/**
 * SSE transport for MCP, using a synchronous blocking approach
 * compatible with NeuronAI's interface.
 */
class SseHttpTransport implements McpTransportInterface
{
    protected const MAX_BUFFER_SIZE = 10 * 1024 * 1024;
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

        if (!filter_var($this->config['url'], FILTER_VALIDATE_URL)) {
            throw new McpException('Invalid URL format');
        }

        try {
            $headers = $this->getAuthHeaders();

            if ($this->sessionId !== null) {
                $headers['Mcp-Session-Id'] = $this->sessionId;
            }

            $headers['Accept'] = 'text/event-stream';

            $headerString = $this->buildHeaderString($headers);

            $context = stream_context_create([
                'http' => [
                    'method' => 'GET',
                    'header' => $headerString,
                    'timeout' => $this->config['timeout'] ?? 30,
                ],
                'ssl' => [
                    'verify_peer' => $this->config['verify'] ?? true,
                    'verify_peer_name' => $this->config['verify'] ?? true,
                ],
            ]);

            $this->sseStream = @fopen($this->config['url'], 'r', false, $context);

            if ($this->sseStream === false) {
                throw new McpException('Failed to open SSE connection to: ' . $this->config['url']);
            }

            stream_set_blocking($this->sseStream, false);

            $meta = stream_get_meta_data($this->sseStream);
            if (isset($meta['wrapper_data']) && is_array($meta['wrapper_data'])) {
                foreach ($meta['wrapper_data'] as $header) {
                    if (stripos((string) $header, 'HTTP/') === 0 && in_array(preg_match('/HTTP\/\d\.\d\s+200/', (string) $header), [0, false], true)) {
                        $this->cleanup();
                        throw new McpException('SSE connection failed: ' . $header);
                    }
                    if (stripos((string) $header, 'Mcp-Session-Id:') === 0) {
                        $this->sessionId = trim(substr((string) $header, 15));
                    }
                }
            }

            $this->waitForEndpoint();

            $this->connected = true;

        } catch (GuzzleException $e) {
            $this->cleanup();
            throw new McpException('HTTP connection failed: ' . $e->getMessage(), $e->getCode(), $e);
        }
    }

    /**
     * MCP SSE handshake: the server must announce the POST endpoint
     * via an 'endpoint' SSE event before any request can be sent.
     *
     * @throws McpException
     */
    protected function waitForEndpoint(): void
    {
        $timeout = microtime(true) + 10;
        $endpointReceived = false;

        while (!$endpointReceived && microtime(true) < $timeout) {
            if ($this->sseStream === null) {
                throw new McpException('SSE stream closed while waiting for endpoint');
            }

            $data = fread($this->sseStream, 8192);

            if ($data !== false && $data !== '') {
                $this->sseBuffer .= $data;

                if (strlen($this->sseBuffer) > self::MAX_BUFFER_SIZE) {
                    throw new McpException('SSE buffer exceeded maximum size');
                }

                // Complete SSE events are framed by a blank line
                while (($pos = strpos($this->sseBuffer, "\n\n")) !== false) {
                    $eventBlock = substr($this->sseBuffer, 0, $pos);
                    $this->sseBuffer = substr($this->sseBuffer, $pos + 2);

                    $parsed = $this->parseSseEvent($eventBlock);

                    if ($parsed['event'] === 'endpoint' && $parsed['data'] !== '') {
                        $this->postEndpointUrl = $this->resolveEndpointUrl($this->config['url'], $parsed['data']);
                        $endpointReceived = true;
                        break;
                    }
                }
            }

            if (!$endpointReceived) {
                usleep(10000); // avoid busy waiting
            }
        }

        if (!$endpointReceived) {
            throw new McpException('Timeout waiting for endpoint event from server');
        }
    }

    /**
     * @return array{event: string, data: string, id: ?string}
     */
    protected function parseSseEvent(string $eventBlock): array
    {
        $event = 'message';
        $data = '';
        $id = null;

        foreach (explode("\n", $eventBlock) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if (str_starts_with($line, ':')) {
                continue;
            }

            if (str_starts_with($line, 'event:')) {
                $event = trim(substr($line, 6));
            } elseif (str_starts_with($line, 'data:')) {
                $data .= trim(substr($line, 5));
            } elseif (str_starts_with($line, 'id:')) {
                $id = trim(substr($line, 3));
            }
        }

        return ['event' => $event, 'data' => $data, 'id' => $id];
    }

    /**
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
            $headers = array_merge($this->getAuthHeaders(), [
                'Content-Type' => 'application/json',
            ]);

            if ($this->sessionId !== null) {
                $headers['Mcp-Session-Id'] = $this->sessionId;
            }

            $jsonData = json_encode($data, JSON_THROW_ON_ERROR);

            $response = $this->httpClient->post($this->postEndpointUrl, [
                'headers' => $headers,
                'body' => $jsonData,
            ]);

            $statusCode = $response->getStatusCode();

            // SSE-based MCP: POST typically returns 202 Accepted, the actual response arrives on the SSE stream
            if ($statusCode !== 202 && $statusCode !== 200) {
                $body = (string) $response->getBody();
                throw new McpException("POST request failed with status {$statusCode}: " . ($body !== '' && $body !== '0' ? $body : '(no body)'));
            }

        } catch (GuzzleException $e) {
            throw new McpException('HTTP POST failed: ' . $e->getMessage(), $e->getCode(), $e);
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
        if (!$this->connected) {
            throw new McpException('Not connected to server. Call connect() first.');
        }

        if ($this->sseStream === null) {
            throw new McpException('SSE stream is not available');
        }

        $timeout = microtime(true) + ($this->config['timeout'] ?? 30);
        $received = false;
        $response = null;

        while (!$received && microtime(true) < $timeout) {
            $data = fread($this->sseStream, 8192);

            if ($data === false) {
                if (feof($this->sseStream)) {
                    throw new McpException('SSE stream closed by server');
                }
            } elseif ($data !== '') {
                $this->sseBuffer .= $data;

                if (strlen($this->sseBuffer) > self::MAX_BUFFER_SIZE) {
                    throw new McpException('SSE buffer exceeded maximum size');
                }

                while (($pos = strpos($this->sseBuffer, "\n\n")) !== false) {
                    $eventBlock = substr($this->sseBuffer, 0, $pos);
                    $this->sseBuffer = substr($this->sseBuffer, $pos + 2);

                    $parsed = $this->parseSseEvent($eventBlock);

                    // JSON-RPC responses arrive as 'message' events
                    if ($parsed['event'] === 'message' && $parsed['data'] !== '') {
                        try {
                            $message = json_decode($parsed['data'], true, 64, JSON_THROW_ON_ERROR);

                            if (isset($message['jsonrpc']) && $message['jsonrpc'] === '2.0') {
                                $response = $message;
                                $received = true;
                                break;
                            }
                        } catch (JsonException) {
                            // Ignore invalid JSON, continue reading
                        }
                    }
                }
            }

            if (!$received) {
                usleep(10000); // avoid busy waiting
            }
        }

        if (!$received || $response === null) {
            throw new McpException('Timeout waiting for response from server');
        }

        return $response;
    }

    public function disconnect(): void
    {
        $this->cleanup();
    }

    protected function cleanup(): void
    {
        $this->connected = false;

        if ($this->sseStream !== null) {
            fclose($this->sseStream);
            $this->sseStream = null;
        }

        $this->sseBuffer = '';
        $this->postEndpointUrl = null;
        $this->sessionId = null;
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
     * @param array<string, string> $headers
     * @throws McpException
     */
    protected function buildHeaderString(array $headers): string
    {
        $headerLines = [];
        foreach ($headers as $key => $value) {
            if (strpbrk($key, "\r\n") !== false || strpbrk($value, "\r\n") !== false) {
                throw new McpException('Header values must not contain line breaks');
            }
            $headerLines[] = "$key: $value";
        }
        return implode("\r\n", $headerLines) . "\r\n";
    }

    protected function resolveEndpointUrl(string $base, string $relative): string
    {
        // An absolute endpoint URL must point back to the same host as the MCP server
        if (str_contains($relative, '://') || str_starts_with($relative, '//')) {
            $normalized = str_starts_with($relative, '//') ? 'https:' . $relative : $relative;
            $relativeParts = parse_url($normalized);
            $baseParts = parse_url($base);

            if ($relativeParts === false || $baseParts === false) {
                throw new McpException('Invalid endpoint URL');
            }

            if (($relativeParts['scheme'] ?? 'https') !== ($baseParts['scheme'] ?? 'https')
                || ($relativeParts['host'] ?? '') !== ($baseParts['host'] ?? '')
                || ($relativeParts['port'] ?? null) !== ($baseParts['port'] ?? null)
            ) {
                throw new McpException('Endpoint URL must point to the same host as the MCP server');
            }

            return $relative;
        }

        $baseParts = parse_url($base);
        if ($baseParts === false) {
            throw new McpException('Invalid base URL');
        }

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

        if (str_starts_with($relative, '/')) {
            return $authority . $relative;
        }
        // Relative path to base path's directory
        $basePath = $baseParts['path'] ?? '/';
        $lastSlashPos = strrpos($basePath, '/');
        $baseDir = $lastSlashPos === false || $lastSlashPos === 0 ? '/' : substr($basePath, 0, $lastSlashPos + 1);
        return $authority . $baseDir . $relative;
    }
}
