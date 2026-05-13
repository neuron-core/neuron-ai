<?php

declare(strict_types=1);

namespace NeuronAI\Agent\Skills\Tools\ToolExecutor;

use NeuronAI\Agent\Skills\Tools\ToolDefinition;
use NeuronAI\Agent\Skills\Tools\ToolExecutorInterface;
use NeuronAI\Agent\Skills\Tools\ToolResult;

use function json_encode;

class McpToolExecutor implements ToolExecutorInterface
{
    /** @var callable|null Client that sends requests to MCP servers */
    private $client = null;

    /**
     * @param callable|null $client fn(string $serverUrl, string $toolName, array $inputs, array $config): array
     */
    public function __construct(?callable $client = null)
    {
        $this->client = $client;
    }

    public function supports(string $type): bool
    {
        return $type === 'mcp';
    }

    public function execute(ToolDefinition $definition, array $inputs): ToolResult
    {
        $config = $definition->execution;

        if ($this->client === null) {
            return new ToolResult(
                exitCode: 1,
                error: 'MCP executor requires a client callable to be configured.',
            );
        }

        $serverUrl = $config['server_url'] ?? '';
        $toolName = $config['tool_name'] ?? $definition->name;
        $timeout = (int) ($config['timeout'] ?? 30);

        try {
            $result = ($this->client)($serverUrl, $toolName, $inputs, $config);

            return new ToolResult(
                exitCode: 0,
                output: is_string($result) ? $result : (string) json_encode($result),
                metadata: ['server' => $serverUrl, 'mcp_tool' => $toolName],
            );
        } catch (\Throwable $e) {
            return new ToolResult(
                exitCode: 1,
                error: $e->getMessage(),
            );
        }
    }
}
