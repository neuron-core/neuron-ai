# MCP Module

Model Context Protocol connector. An MCP server's tools become ordinary Neuron tools, so the agent loop, the approval gate and observability treat them exactly like local ones; nothing MCP-specific leaks past `ToolInterface`.

## How it fits together

- `McpConnector` is the developer-facing entry point. `tools()` lists the server's tools, applies the `only()` / `exclude()` filters, and wraps each one in an `McpTool`: a `Tool` subclass whose `__invoke()` forwards the call back through the connector. Its input schema is translated into the regular `ToolProperty` / `ArrayProperty` / `ObjectProperty` definitions.
- `McpClient` speaks JSON-RPC over an `McpTransportInterface`, selected from the config: `command` → `StdioTransport` (local process), `url` → `StreamableHttpTransport`, or `SseHttpTransport` when `async` is true; a custom `transport` instance can be passed instead. HTTP transports accept an optional `HttpClientInterface` (default `CurlHttpClient`).
- The client speaks the handshake-based protocol revisions: it requests `2025-11-25` in `initialize`, hands the version the server settles on to the transport, and `StreamableHttpTransport` echoes it as the `MCP-Protocol-Version` header on every later request. The stateless `2026-07-28` revision (no handshake, per-request `_meta`) is not implemented.
- The connector serializes to its config and filters only; the client, transport and HTTP client are dropped and rebuilt lazily on first use after unserialize.

```php
protected function tools(): array
{
    return [
        ...McpConnector::make(['command' => 'php', 'args' => ['/path/to/mcp_server.php']])->tools(),
        ...McpConnector::make(['url' => 'https://mcp.example.com', 'token' => env('MCP_BEARER_TOKEN')])
            ->only(['search', 'read'])
            ->tools(),
    ];
}
```

`FakeMcpTransport` (`src/Testing/`) implements the transport contract for tests: queue JSON-RPC responses, then assert what was sent.
