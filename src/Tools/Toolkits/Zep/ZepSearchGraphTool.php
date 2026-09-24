<?php

declare(strict_types=1);

namespace NeuronAI\Tools\Toolkits\Zep;

use NeuronAI\HttpClient\Curl\CurlHttpClient;
use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;

use function array_map;

/**
 * https://help.getzep.com/sdk-reference/graph/search
 *
 * @method static static make(string $key, string $user_id, ?HttpClientInterface $httpClient = null)
 * @deprecated The Zep toolkit will be removed in the next major version.
 */
class ZepSearchGraphTool extends Tool
{
    use HandleZepClient;

    protected string $name = 'search_knowledge_graph';

    protected ?string $description = 'Searches the knowledge graph for relevant facts or nodes.
	Use this tool if you need to retrieve user information that can help you provide more accurate answers.';

    public function __construct(
        protected string $key,
        protected string $user_id,
        ?HttpClientInterface $httpClient = null,
    ) {
        $this->httpClient = $httpClient ?? new CurlHttpClient();
    }

    protected function properties(): array
    {
        return [
            new ToolProperty(
                'query',
                PropertyType::STRING,
                'The search term to find relevant facts or nodes',
                true
            ),
            new ToolProperty(
                'search_scope',
                PropertyType::STRING,
                'The scope of the search to perform. Can be "facts" or "nodes"',
                false,
                ['facts', 'nodes']
            ),
        ];
    }

    public function __invoke(string $query, string $search_scope = 'facts', int $limit = 5): array
    {
        $this->createUser();

        $response = $this->post('graph/search', [
            'user_id' => $this->user_id,
            'query' => $query,
            'scope' => $search_scope === 'facts' ? 'edges' : 'nodes',
            'limit' => $limit,
        ]);

        $response = $response->json();

        return match ($search_scope) {
            'nodes' => $this->mapNodes($response['nodes'] ?? []),
            default => $this->mapEdges($response['edges'] ?? []),
        };
    }

    protected function mapEdges(array $edges): array
    {
        return array_map(fn (array $edge): array => [
            'fact' => $edge['fact'],
            'created_at' => $edge['created_at'],
        ], $edges);
    }

    protected function mapNodes(array $nodes): array
    {
        return array_map(fn (array $node): array => [
            'name' => $node['name'],
            'summary' => $node['summary'],
        ], $nodes);
    }
}
