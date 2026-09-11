<?php

declare(strict_types=1);

namespace NeuronAI\MCP;

use JsonException;
use NeuronAI\Exceptions\ArrayPropertyException;
use NeuronAI\Exceptions\ToolException;
use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\StaticConstructor;
use NeuronAI\Tools\ToolInterface;
use NeuronAI\Tools\ToolPropertyFactory;
use Exception;
use ReflectionException;

use function array_filter;
use function array_key_exists;
use function array_map;
use function call_user_func;
use function in_array;
use function is_array;

/**
 * @method static static make(array<string, mixed> $config)
 */
class McpConnector
{
    use StaticConstructor;

    protected McpClient $client;

    /**
     * @var string[]
     */
    protected array $exclude = [];

    /**
     * @var string[]
     */
    protected array $only = [];

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(
        protected array $config,
        protected ?HttpClientInterface $httpClient = null,
    ) {
    }

    /**
     * @throws McpException
     * @throws JsonException
     */
    protected function client(): McpClient
    {
        return $this->client ??= new McpClient($this->config, $this->httpClient);
    }

    public function __serialize(): array
    {
        return [
            'config' => $this->config,
            'only' => $this->only,
            'exclude' => $this->exclude,
        ];
    }

    public function __unserialize(array $data): void
    {
        $this->config = $data['config'];
        $this->only = $data['only'];
        $this->exclude = $data['exclude'];
        $this->httpClient = null;
    }

    /**
     * @param  string[]  $tools
     */
    public function exclude(array $tools): McpConnector
    {
        $this->exclude = $tools;
        return $this;
    }

    /**
     * @param  string[]  $tools
     */
    public function only(array $tools): McpConnector
    {
        $this->only = $tools;
        return $this;
    }

    /**
     * @return ToolInterface[]
     * @throws Exception
     */
    public function tools(): array
    {
        $tools = array_filter(
            $this->client()->listTools(),
            fn (array $tool): bool =>
                !in_array($tool['name'], $this->exclude) &&
                ($this->only === [] || in_array($tool['name'], $this->only)),
        );

        return array_map($this->createTool(...), $tools);
    }

    /**
     * @param array<string, mixed> $item
     */
    protected function createTool(array $item): ToolInterface
    {
        $tool = new McpTool(
            name: $item['name'],
            description: $item['description'] ?? null,
            annotations: $item['annotations'] ?? [],
            connector: $this,
            item: $item,
        );

        foreach (ToolPropertyFactory::fromSchema($item['inputSchema'] ?? []) as $property) {
            $tool->addProperty($property);
        }

        return $tool;
    }

    /**
     * Tools delegate invocation back to the connector: interrupt
     * serialization cannot serialize an MCP connection held by a tool.
     *
     * @throws McpException
     * @throws JsonException
     */
    public function invokeTool(array $item, array $arguments): mixed
    {
        $response = call_user_func(
            $this->client()->callTool(...),
            $item['name'],
            $arguments
        );

        if (array_key_exists('error', $response)) {
            throw new McpException($response['error']['message']);
        }

        if (isset($response['result']) && is_array($response['result']) && array_key_exists('content', $response['result'])) {
            return $response['result']['content'];
        }

        return '';
    }
}
