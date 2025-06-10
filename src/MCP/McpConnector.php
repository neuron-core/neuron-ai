<?php

namespace NeuronAI\MCP;

use NeuronAI\StaticConstructor;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolInterface;

class McpConnector
{
    use StaticConstructor;

    protected McpClient $client;

    public function __construct(array $config)
    {
        $this->client = new McpClient($config);
    }

    /**
     * Get the list of available Tools from the server.
     *
     * @return ToolInterface[]
     * @throws \Exception
     */
    public function tools(): array
    {
        $tools = $this->client->listTools();

        return \array_map(fn ($tool) => $this->createTool($tool), $tools);
    }

    /**
     * Convert the list of tools from the MCP server to Neuron compatible entities.
     */
    protected function createTool(array $item): ToolInterface
    {
        $tool = Tool::make(
            name: $item['name'],
            description: $item['description'] ?? ''
        )->setCallable(function (...$arguments) use ($item) {
            $response = call_user_func($this->client->callTool(...), $item['name'], $arguments);

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

        foreach ($item['inputSchema']['properties'] as $name => $input) {
            $required = \in_array($name, $item['inputSchema']['required'] ?? []);
            $types = \is_array($input['type']) ? $input['type'] : [$input['type']];

            foreach ($types as $type) {
                try {
                    $type = PropertyType::from($type);
                    break;
                } catch (\Throwable $e) {
                }
            }

            $property = new ToolProperty(
                name: $name,
                type: $type ?? PropertyType::STRING,
                description: $input['description'] ?? '',
                required: $required,
                enum: $input['items']['enum'] ?? []
            );

            $tool->addProperty($property);
        }

        return $tool;
    }
}
