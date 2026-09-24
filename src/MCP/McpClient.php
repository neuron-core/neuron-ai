<?php

declare(strict_types=1);

namespace NeuronAI\MCP;

use Exception;
use JsonException;
use NeuronAI\HttpClient\HttpClientInterface;
use stdClass;

use function array_filter;
use function array_merge;
use function getmypid;
use function is_null;

class McpClient
{
    /**
     * The newest revision still negotiated through the initialize handshake:
     * from 2026-07-28 the version travels in every request's _meta instead.
     */
    protected const PROTOCOL_VERSION = '2025-11-25';

    protected McpTransportInterface $transport;

    protected int $requestId = 0;

    /**
     * The process that opened the session.
     */
    protected ?int $sessionProcessId = null;

    /**
     * Transports inherited from a parent process, never used again (see leaveInheritedSession()).
     *
     * @var array<int, McpTransportInterface>
     */
    protected array $inheritedTransports = [];

    /**
     * Create a new MCP client with the given transport
     *
     * @param array<string, mixed> $config
     *
     * @throws McpException
     * @throws JsonException
     */
    public function __construct(
        protected array $config,
        protected ?HttpClientInterface $httpClient = null,
    ) {
        $this->transport = $this->createTransport();
        $this->openSession();
    }

    public function __destruct()
    {
        // Ending a session opened by another process would stop that process's server.
        if ($this->sessionProcessId === (int) getmypid()) {
            $this->transport->disconnect();
        }
    }

    /**
     * @throws McpException
     */
    protected function createTransport(): McpTransportInterface
    {
        if (($this->config['transport'] ?? null) instanceof McpTransportInterface) {
            return $this->config['transport'];
        }

        if (isset($this->config['command'])) {
            return new StdioTransport($this->config);
        }

        if (isset($this->config['url'])) {
            return ($this->config['async'] ?? false)
                ? new SseHttpTransport($this->config, $this->httpClient)
                : new StreamableHttpTransport($this->config, $this->httpClient);
        }

        throw new McpException('Transport not supported! Provide either "command" for StdioTransport, "url" for StreamableHttpTransport/SseHttpTransport, or a custom "transport" instance.');
    }

    /**
     * @throws McpException|JsonException
     */
    protected function openSession(): void
    {
        $this->transport->connect();
        $this->initialize();
        $this->sessionProcessId = (int) getmypid();
    }

    /**
     * @throws McpException|JsonException
     */
    protected function initialize(): void
    {
        $response = $this->exchange('initialize', [
            'protocolVersion' => self::PROTOCOL_VERSION,
            'capabilities' => new stdClass(),
            'clientInfo' => (object) [
                'name' => 'neuron-ai',
                'version' => '1.0.0',
            ],
        ]);

        $this->transport->setProtocolVersion($response['result']['protocolVersion'] ?? self::PROTOCOL_VERSION);

        $this->transport->send([
            'jsonrpc' => '2.0',
            'method' => 'notifications/initialized',
        ]);
    }

    /**
     * List all available tools from the MCP server
     *
     * @return array<int, array<string, mixed>>
     *
     * @throws Exception
     */
    public function listTools(): array
    {
        $tools = [];
        $cursor = null;

        do {
            $response = $this->request('tools/list', $cursor === null ? [] : ['cursor' => $cursor]);
            $tools = array_merge($tools, $response['result']['tools']);
            $cursor = $response['result']['nextCursor'] ?? null;
        } while ($cursor !== null);

        return $tools;
    }

    /**
     * Call a tool on the MCP server
     *
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     *
     * @throws Exception
     */
    public function callTool(string $toolName, array $arguments = []): array
    {
        $arguments = array_filter($arguments, fn (mixed $value): bool => ! is_null($value));

        return $this->request('tools/call', [
            'name' => $toolName,
            'arguments' => $arguments !== [] ? $arguments : new stdClass(),
        ]);
    }

    /**
     * Send a request within this process's session. When the session was lost before the
     * request reached the server, a new session takes it.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     *
     * @throws McpException|JsonException
     */
    protected function request(string $method, array $params = []): array
    {
        $this->leaveInheritedSession();

        try {
            return $this->exchange($method, $params);
        } catch (McpSessionLostException) {
            $this->transport->disconnect();
            $this->openSession();

            return $this->exchange($method, $params);
        }
    }

    /**
     * A session belongs to the process that opened it: a forked child writing to its parent's
     * server process or HTTP session would interleave its requests with the parent's. A child
     * opens its own session and keeps the inherited transport referenced but unused, so the
     * parent's session stays open. A transport the application passed in stays the
     * application's, in every process.
     *
     * @throws McpException|JsonException
     */
    protected function leaveInheritedSession(): void
    {
        if ($this->sessionProcessId === (int) getmypid() || ($this->config['transport'] ?? null) instanceof McpTransportInterface) {
            return;
        }

        $this->inheritedTransports[] = $this->transport;
        $this->transport = $this->createTransport();
        $this->openSession();
    }

    /**
     * Send one request and wait for its response. Notifications, server requests and
     * responses to requests given up on may arrive first: they are skipped.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     *
     * @throws McpException|JsonException
     */
    protected function exchange(string $method, array $params): array
    {
        $request = ['jsonrpc' => '2.0', 'id' => ++$this->requestId, 'method' => $method];
        if ($params !== []) {
            $request['params'] = $params;
        }

        $this->transport->send($request);

        do {
            $message = $this->transport->receive();
        } while (isset($message['method']) || ($message['id'] ?? null) !== $request['id']);

        return $message;
    }
}
