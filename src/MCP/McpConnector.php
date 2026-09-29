<?php

declare(strict_types=1);

namespace NeuronAI\MCP;

use JsonException;
use NeuronAI\Chat\Enums\SourceType;
use NeuronAI\Chat\Messages\ContentBlocks\AudioContent;
use NeuronAI\Chat\Messages\ContentBlocks\ContentBlockInterface;
use NeuronAI\Chat\Messages\ContentBlocks\ImageContent;
use NeuronAI\Chat\Messages\ContentBlocks\TextContent;
use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\StaticConstructor;
use NeuronAI\Tools\ToolInterface;
use NeuronAI\Tools\ToolOutput;
use NeuronAI\Tools\ToolPropertyFactory;
use Exception;

use function array_filter;
use function array_map;
use function array_values;
use function call_user_func;
use function in_array;
use function is_array;
use function is_string;
use function json_encode;

use const JSON_THROW_ON_ERROR;

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
     * @var array<string, callable>
     */
    protected array $with = [];

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
     * Configure the tool published under $name before it is handed out: the
     * callback may mutate the tool in place, or return a replacement for it.
     */
    public function with(string $name, callable $callback): McpConnector
    {
        $this->with[$name] = $callback;
        return $this;
    }

    /**
     * @return ToolInterface[]
     * @throws Exception
     */
    public function tools(): array
    {
        $tools = array_values(array_filter(
            $this->client()->listTools(),
            fn (array $tool): bool =>
                !in_array($tool['name'], $this->exclude, true) &&
                ($this->only === [] || in_array($tool['name'], $this->only, true)),
        ));

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

        if (isset($this->with[$item['name']])) {
            return $this->with[$item['name']]($tool) ?? $tool;
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
    public function invokeTool(array $item, array $arguments): ToolOutput
    {
        $response = call_user_func(
            $this->client()->callTool(...),
            $item['name'],
            $arguments
        );

        $result = is_array($response['result'] ?? null) ? $response['result'] : [];
        $content = is_array($result['content'] ?? null) ? array_filter($result['content'], is_array(...)) : [];
        $blocks = array_map($this->contentBlock(...), array_values($content));

        // The spec asks servers to repeat structured content as text: one that does not still reaches the model
        if ($blocks === [] && is_array($result['structuredContent'] ?? null)) {
            $blocks = [new TextContent(json_encode($result['structuredContent'], JSON_THROW_ON_ERROR))];
        }

        return new ToolOutput($blocks, ($result['isError'] ?? false) === true);
    }

    /**
     * The protocol's text, image and audio content become the framework's blocks. Any other
     * item, including one that breaks the spec, reaches the model as its JSON.
     *
     * @param array<string, mixed> $item
     * @throws JsonException
     */
    protected function contentBlock(array $item): ContentBlockInterface
    {
        $type = $item['type'] ?? null;
        $data = $item['data'] ?? null;
        $mimeType = $item['mimeType'] ?? null;
        $isBinary = is_string($data) && is_string($mimeType);

        return match (true) {
            $type === 'text' && is_string($item['text'] ?? null) => new TextContent($item['text']),
            $type === 'image' && $isBinary => new ImageContent($data, SourceType::BASE64, $mimeType),
            $type === 'audio' && $isBinary => new AudioContent($data, SourceType::BASE64, $mimeType),
            default => new TextContent(json_encode($item, JSON_THROW_ON_ERROR)),
        };
    }
}
