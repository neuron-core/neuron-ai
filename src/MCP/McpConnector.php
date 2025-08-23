<?php

declare(strict_types=1);

namespace NeuronAI\MCP;

use NeuronAI\Exceptions\ArrayPropertyException;
use NeuronAI\Exceptions\ToolException;
use NeuronAI\StaticConstructor;
use NeuronAI\Tools\ArrayProperty;
use NeuronAI\Tools\ObjectProperty;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolInterface;
use NeuronAI\Tools\ToolProperty;

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
     * @throws McpException
     */
    public function __construct(array $config)
    {
        $this->client = new McpClient($config);
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
     * @throws \Exception
     */
    public function tools(): array
    {
        // Filter by the only and exclude preferences.
        $tools = \array_filter(
            $this->client->listTools(),
            fn (array $tool): bool =>
                !\in_array($tool['name'], $this->exclude) &&
                ($this->only === [] || \in_array($tool['name'], $this->only)),
        );

        return \array_map(fn (array $tool): ToolInterface => $this->createTool($tool), $tools);
    }

    /**
     * Convert the list of tools from the MCP server to Neuron compatible entities.
     *
     * @param array<string, mixed> $item
     * @throws ArrayPropertyException
     * @throws \ReflectionException
     * @throws ToolException
     */
    protected function createTool(array $item): ToolInterface
    {
        $tool = Tool::make(
            name: $item['name'],
            description: $item['description'] ?? null
        )->setCallable(function (...$arguments) use ($item) {
            $response = \call_user_func($this->client->callTool(...), $item['name'], $arguments);

            if (\array_key_exists('error', $response)) {
                throw new McpException($response['error']['message']);
            }

            $response = $response['result']['content'][0];

            if ($response['type'] === 'text') {
                return $response['text'];
            }

            if ($response['type'] === 'image') {
                return $response;
            }

            throw new McpException("Tool response format not supported: {$response['type']}");
        });

        foreach ($item['inputSchema']['properties'] as $name => $prop) {
            $required = \in_array($name, $item['inputSchema']['required'] ?? []);

            $type = PropertyType::fromSchema($prop['type'] ?? PropertyType::STRING->value);

            $property = match ($type) {
                PropertyType::ARRAY => $this->createArrayProperty($name, $required, $prop),
                PropertyType::OBJECT => $this->createObjectProperty($name, $required, $prop),
                default => $this->createToolProperty($name, $type, $required, $prop),
            };

            $tool->addProperty($property);
        }

        return $tool;
    }

    /**
     * @param array<string, mixed> $prop
     */
    protected function createToolProperty(string $name, PropertyType $type, bool $required, array $prop): ToolProperty
    {
        return new ToolProperty(
            name: $name,
            type: $type,
            description: $prop['description'] ?? null,
            required: $required,
            enum: $prop['items']['enum'] ?? []
        );
    }

    /**
     * @param array<string, mixed> $prop
     * @throws ArrayPropertyException
     */
    protected function createArrayProperty(string $name, bool $required, array $prop): ArrayProperty
    {
        return new ArrayProperty(
            name: $name,
            description: $prop['description'] ?? null,
            required: $required,
            items: new ToolProperty(
                name: 'type',
                type: PropertyType::from($prop['items']['type'] ?? 'string'),
            )
        );
    }

    /**
     * @param array<string, mixed> $prop
     * @throws ArrayPropertyException
     * @throws ToolException
     * @throws \ReflectionException
     */
    protected function createObjectProperty(string $name, bool $required, array $prop): ObjectProperty
    {
        return new ObjectProperty(
            name: $name,
            description: $prop['description'] ?? null,
            required: $required,
        );
    }
}
