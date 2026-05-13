<?php

declare(strict_types=1);

namespace NeuronAI\MCP;

use NeuronAI\Exceptions\ArrayPropertyException;
use NeuronAI\Exceptions\ToolException;
use NeuronAI\StaticConstructor;
use NeuronAI\Tools\ArrayProperty;
use NeuronAI\Tools\ObjectProperty;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\ToolInterface;
use NeuronAI\Tools\ToolProperty;
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
    public function __construct(protected array $config)
    {
    }

    /**
     * @throws McpException
     */
    protected function client(): McpClient
    {
        return $this->client ??= new McpClient($this->config);
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
     * Get the list of available Tools from the server.
     *
     * @return ToolInterface[]
     * @throws Exception
     */
    public function tools(): array
    {
        // Filter by the only and exclude preferences.
        $tools = array_filter(
            $this->client()->listTools(),
            fn (array $tool): bool =>
                !in_array($tool['name'], $this->exclude) &&
                ($this->only === [] || in_array($tool['name'], $this->only)),
        );

        return array_map($this->createTool(...), $tools);
    }

    /**
     * Convert the list of tools from the MCP server to Neuron compatible entities.
     *
     * @param array<string, mixed> $item
     * @throws ArrayPropertyException
     * @throws ReflectionException
     * @throws ToolException
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

        // If the tool has no properties, return early
        if (!isset($item['inputSchema']['properties']) || !is_array($item['inputSchema']['properties'])) {
            return $tool;
        }

        foreach ($item['inputSchema']['properties'] as $name => $prop) {
            $required = in_array($name, $item['inputSchema']['required'] ?? []);

            $typeSchema = $prop['type'] ?? PropertyType::STRING->value;
            $type = PropertyType::fromSchema($typeSchema);
            $nullable = is_array($typeSchema) && in_array('null', $typeSchema, true);

            $property = match ($type) {
                PropertyType::ARRAY => $this->createArrayProperty($name, $required, $prop, $nullable),
                PropertyType::OBJECT => $this->createObjectProperty($name, $required, $prop, $nullable),
                default => $this->createToolProperty($name, $type, $required, $prop, $nullable),
            };

            $tool->addProperty($property);
        }

        return $tool;
    }

    /**
     * @param array<string, mixed> $prop
     */
    protected function createToolProperty(string $name, PropertyType $type, bool $required, array $prop, bool $nullable = false): ToolProperty
    {
        return new ToolProperty(
            name: $name,
            type: $type,
            description: $prop['description'] ?? null,
            required: $required,
            enum: $prop['items']['enum'] ?? $prop['enum'] ?? [],
            nullable: $nullable,
        );
    }

    /**
     * @param array<string, mixed> $prop
     * @throws ArrayPropertyException
     */
    protected function createArrayProperty(string $name, bool $required, array $prop, bool $nullable = false): ArrayProperty
    {
        return new ArrayProperty(
            name: $name,
            description: $prop['description'] ?? null,
            required: $required,
            items: new ToolProperty(
                name: 'type',
                type: PropertyType::from($prop['items']['type'] ?? 'string'),
            ),
            nullable: $nullable,
        );
    }

    /**
     * @param array<string, mixed> $prop
     * @throws ArrayPropertyException
     * @throws ToolException
     * @throws ReflectionException
     */
    protected function createObjectProperty(string $name, bool $required, array $prop, bool $nullable = false): ObjectProperty
    {
        return new ObjectProperty(
            name: $name,
            description: $prop['description'] ?? null,
            required: $required,
            nullable: $nullable,
        );
    }

    /**
     * This might look counter-intuitive, but when dealing with interrupts and serialization PHP doesnt allow for MCP connectors serialization
     * @throws McpException
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
