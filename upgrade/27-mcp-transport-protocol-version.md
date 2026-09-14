# Upgrade: MCP transports carry the negotiated protocol version

## Summary

In 3.x the MCP client announced protocol version `2024-11-05` in its `initialize` request and
never told the transport which version the server settled on. The Streamable HTTP transport of
the current specification expects every request after the handshake to carry the negotiated
version in an `MCP-Protocol-Version` header, and servers may reject requests without it.

In 4.x the client announces `2025-11-25`, reads `result.protocolVersion` from the server's
`initialize` response (falling back to its own version when the server omits it) and hands it to
the transport through a new contract method:

```php
McpTransportInterface::setProtocolVersion(string $version): void
```

The built-in transports already implement it: `StreamableHttpTransport` stores the version and
adds the header to every later request, clearing it with the session on `disconnect()`;
`StdioTransport` and `SseHttpTransport` implement it as a no-op, since stdio has no headers and
the HTTP+SSE transport predates the header. A custom transport passed through the `transport`
configuration key must implement the method or it fails at load time.

| 3.x | 4.x |
|---|---|
| `McpTransportInterface`: `connect()`, `send()`, `receive()`, `disconnect()` | Plus `setProtocolVersion(string $version): void`, called once, right after the `initialize` response |
| The client announces `2024-11-05` | The client announces `2025-11-25` and forwards whatever version the server settles on |
| No `MCP-Protocol-Version` header | `StreamableHttpTransport` sends it on every request after the handshake |

## What to Search For

Search the whole application, including tests and config, excluding `vendor/`:

```
grep -rn "implements McpTransportInterface" --include="*.php" .
grep -rn "'transport' =>" --include="*.php" .
grep -rn "2024-11-05" --include="*.php" --include="*.json" .
```

The first finds custom transports. The second finds where they are wired into an
`McpConnector` configuration. The third finds tests or fixtures pinned to the old announced
version.

## How to Refactor

### Case 1: A custom HTTP transport

Before:

```php
final class MyHttpTransport implements McpTransportInterface
{
    public function connect(): void
    {
    }

    public function send(array $data): void
    {
        $this->post($data, ['Content-Type' => 'application/json']);
    }

    public function receive(): array
    {
        return $this->nextResponse();
    }

    public function disconnect(): void
    {
    }
}
```

After:

```php
final class MyHttpTransport implements McpTransportInterface
{
    protected ?string $protocolVersion = null;

    public function connect(): void
    {
    }

    public function send(array $data): void
    {
        $headers = ['Content-Type' => 'application/json'];

        if ($this->protocolVersion !== null) {
            $headers['MCP-Protocol-Version'] = $this->protocolVersion;
        }

        $this->post($data, $headers);
    }

    public function receive(): array
    {
        return $this->nextResponse();
    }

    public function setProtocolVersion(string $version): void
    {
        $this->protocolVersion = $version;
    }

    public function disconnect(): void
    {
        $this->protocolVersion = null;
    }
}
```

The `initialize` request itself goes out before the version is known, which is what the
specification expects.

### Case 2: A custom transport without headers

A transport over stdio or a socket has nowhere to carry the version. Implement the method as a
no-op:

```php
public function setProtocolVersion(string $version): void
{
}
```

### Case 3: Tests pinned to the announced version

Before:

```php
$this->assertSame('2024-11-05', $sent['params']['protocolVersion']);
```

After:

```php
$this->assertSame('2025-11-25', $sent['params']['protocolVersion']);
```

A fake server response may return its own `protocolVersion`; the client forwards it to the
transport unchanged, so a test can assert the negotiated value on the transport rather than the
announced one.

## Checklist

- Every class implementing `McpTransportInterface` declares `setProtocolVersion(string $version): void`.
- HTTP transports send `MCP-Protocol-Version` on every request after the handshake and drop it on disconnect.
- No test expects the `2024-11-05` announcement.
