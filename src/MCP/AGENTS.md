# MCP Module

Model Context Protocol connector for external tool integration. MCP is an open standard by Anthropic to connect agents to external services.

**Dependencies**: `src/HttpClient/AGENTS.md`

## Core

| File | Purpose |
|------|---------|
| `McpConnector.php` | Establishes connection, discovers tools |
| `McpClient.php` | Calls tools on MCP server |
| `McpTransportInterface.php` | Transport contract |
| `McpException.php` | Module exception |

## Transports

| Class | Protocol | Use Case |
|-------|----------|----------|
| `StdioTransport` | Standard I/O | Local MCP processes |
| `SseHttpTransport` | Server-Sent Events | Remote servers with SSE |
| `StreamableHttpTransport` | HTTP streaming | Standard HTTP MCP |

## Usage with Agent Extension Pattern

Connect MCP tools in your custom agent class:

```php
use NeuronAI\Agent;
use NeuronAI\MCP\McpConnector;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Providers\Anthropic\Anthropic;

class MyAgent extends Agent
{
    protected function provider(): AIProviderInterface
    {
        return new Anthropic(
            key: env('ANTHROPIC_API_KEY'),
            model: 'claude-sonnet-4-6',
        );
    }

    public function instructions(): string
    {
        return 'You are a helpful assistant with access to external tools.';
    }

    protected function tools(): array
    {
        return [
            // Local MCP server via stdio
            ...McpConnector::make([
                'command' => 'php',
                'args' => ['/path/to/mcp_server.php'],
            ])->tools(),
        ];
    }
}
```

### Remote MCP Server

```php
use NeuronAI\MCP\McpConnector;

protected function tools(): array
{
    return [
        // HTTP transport
        ...McpConnector::make([
            'url' => 'https://mcp.example.com',
            'token' => env('MCP_BEARER_TOKEN'),
            'timeout' => 30,
        ])->tools(),
    ];
}
```

### SSE Transport (Async)

```php
protected function tools(): array
{
    return [
        ...McpConnector::make([
            'url' => 'https://mcp.example.com',
            'token' => env('MCP_BEARER_TOKEN'),
            'async' => true, // Enables SSE transport
        ])->tools(),
    ];
}
```

## Filtering Tools

Control which tools are exposed from the MCP server:

```php
protected function tools(): array
{
    return [
        // EXCLUDE: discard certain tools
        ...McpConnector::make([
            'url' => 'https://mcp.example.com',
        ])->exclude([
            'dangerous_tool',
            'admin_tool',
        ])->tools(),

        // ONLY: select specific tools
        ...McpConnector::make([
            'url' => 'https://mcp.example.com',
        ])->only([
            'search_tool',
            'read_tool',
        ])->tools(),
    };
}
```

## Multiple MCP Servers

```php
protected function tools(): array
{
    return [
        // Database tools
        ...McpConnector::make([
            'command' => 'mcp-server-postgres',
            'args' => ['postgres://localhost/mydb'],
        ])->tools(),

        // File system tools
        ...McpConnector::make([
            'command' => 'mcp-server-filesystem',
            'args' => ['/home/user/documents'],
        ])->tools(),
    ];
}
```

## MCP Server Directories

Find pre-built MCP servers:
- [MCP Official GitHub](https://github.com/modelcontextprotocol/servers)
- [MCP-GET Registry](https://mcp-get.com/)

## How It Works

1. `McpConnector` establishes connection using configured transport
2. Neuron discovers tools exposed by the MCP server
3. Tools are converted to `ProviderTool` instances
4. When agent calls a tool, Neuron sends request to MCP server
5. Result is returned to the LLM to continue the task

## Dependencies

- `HttpClient` for HTTP transports
