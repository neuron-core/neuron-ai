# MCP Module

Model Context Protocol connector. An MCP server's tools become ordinary Neuron tools, so the agent loop, the approval gate and observability treat them exactly like local ones; nothing MCP-specific leaks past `ToolInterface`.

## How it fits together

- `McpConnector` is the developer-facing entry point. `tools()` lists the server's tools, applies the `only()` / `exclude()` filters, and wraps each one in an `McpTool`: a `Tool` subclass whose `__invoke()` forwards the call back through the connector. `with(name, callback)` configures one tool at creation time, keyed by the server-assigned name because every tool shares the `McpTool` class: the callback may mutate the tool or return a replacement. Its input schema is translated by the shared `ToolPropertyFactory` into regular `ToolProperty` / `ArrayProperty` / `ObjectProperty` definitions, recursively including nested objects and array items. The factory rejects references and composition schemas that the property model cannot represent; see `src/Tools/AGENTS.md`.
- `McpClient` speaks JSON-RPC over an `McpTransportInterface`, selected from the config: `command` → `StdioTransport` (local process), `url` → `StreamableHttpTransport`, or `SseHttpTransport` when `async` is true; a custom `transport` instance can be passed instead. HTTP transports accept an optional `HttpClientInterface` (default `CurlHttpClient`).
- The client speaks the handshake-based protocol revisions: it requests `2025-11-25` in `initialize`, hands the version the server settles on to the transport, and `StreamableHttpTransport` echoes it as the `MCP-Protocol-Version` header on every later request. The stateless `2026-07-28` revision (no handshake, per-request `_meta`) is not implemented.
- The connector serializes to its config and filters only; the client, transport and HTTP client are dropped and rebuilt lazily on first use after unserialize. `with()` callbacks are not serialized either: their effect already lives on the tools they configured.

```php
protected function tools(\NeuronAI\Workflow\ExecutionContext $context): array
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

## Sessions

- **One session per process.** `McpClient` opens its session on construction; the connector creates the client lazily. A forked child (`parallelToolCalls()`) opens its own session, a new server process or HTTP session, and keeps the inherited transport referenced but unused, so the parent's session stays open. Only the process that opened a session ends it. A custom `transport` instance stays the application's in every process.
- **A lost session is replaced.** A transport throws `McpSessionLostException` when a request could not reach the server because its session is gone: an HTTP 404 on a request carrying a session ID, or a stdio server that is no longer running. Nothing was processed, so the client opens a new session and sends the request once more. A failure after delivery, such as a server dying mid-request, is not retried: the request may have run. The next request replaces the session.
- **Messages arrive one at a time.** Stdio reads newline-delimited messages through a buffer kept between reads, and drains the server's stderr while waiting, since a full stderr pipe blocks the server. An SSE response yields each event's payload in order. The client skips notifications, server requests and responses to abandoned requests until its own response arrives; it answers no server request, not even `ping`.
- `SseHttpTransport`, the legacy HTTP+SSE transport, gets per-process sessions from the client but does not recover an expired one.
