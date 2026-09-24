<?php

declare(strict_types=1);

namespace NeuronAI\Tools\Toolkits\Zep;

use NeuronAI\HttpClient\Curl\CurlHttpClient;
use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;

/**
 * @method static static make(string $key, string $user_id, ?HttpClientInterface $httpClient = null)
 * @deprecated The Zep toolkit will be removed in the next major version.
 */
class ZepAddToGraphTool extends Tool
{
    use HandleZepClient;

    protected string $name = 'add_knowledge_graph_data';

    protected ?string $description = 'Add relevant information to the knowledge graph for long term memory.
	Look for facts, news or any relevant information in the conversation that you think is important to store for future use.';

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
                'data',
                PropertyType::STRING,
                'The search term to find relevant facts or nodes',
                true
            ),
            new ToolProperty(
                'type',
                PropertyType::STRING,
                'The scope of the search to perform. Can be "facts" or "nodes"',
                true,
                ['text', 'json', 'message']
            ),
        ];
    }

    public function __invoke(string $data, string $type): string
    {
        $this->createUser();

        $response = $this->post('graph', [
            'user_id' => $this->user_id,
            'data' => $data,
            'type' => $type,
        ]);

        $response = $response->json();

        return $response['content'];
    }
}
