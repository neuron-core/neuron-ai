<?php

declare(strict_types=1);

namespace NeuronAI\Tools\Toolkits\Zep;

use GuzzleHttp\RequestOptions;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;

use function json_decode;

/**
 * @method static static make(string $key, string $user_id)
 */
class ZepAddToGraphTool extends Tool
{
    use HandleZepClient;

    public function __construct(
        protected string $key,
        protected string $user_id
    ) {
        parent::__construct(
            'add_knowledge_graph_data',
            'Add relevant information to the knowledge graph for long term memory.
Look for facts, news or any relevant information in the conversation that you think is important to store for future use.'
        );

        $this->createUser();
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
            )
        ];
    }

    public function __invoke(string $data, string $type): string
    {
        $response = $this->getClient()->post('graph', [
            RequestOptions::JSON => [
                'user_id' => $this->user_id,
                'data' => $data,
                'type' => $type,
            ]
        ])->getBody()->getContents();

        $response = json_decode($response, true);

        return $response['content'];
    }
}
