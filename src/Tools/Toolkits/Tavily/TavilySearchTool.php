<?php

declare(strict_types=1);

namespace NeuronAI\Tools\Toolkits\Tavily;

use NeuronAI\HttpClient\HttpRequest;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\Tool;

use function array_map;
use function array_merge;
use function implode;

/**
 * @method static static make(string $key, array $topics = [])
 */
class TavilySearchTool extends Tool
{
    use HandleTavilyClient;

    protected string $name = 'web_search';

    protected ?string $description;

    protected array $options = [
        'search_depth' => 'basic',
        'chunks_per_source' => 3,
        'max_results' => 3,
    ];

    /**
     * @param string $key Tavily API key.
     * @param array $topics Explicit the topics you want to force the Agent to perform web search.
     */
    public function __construct(
        protected string $key,
        protected array $topics = [],
    ) {
        $this->description = 'Use this tool to search the web for additional information '.
            ($this->topics === [] ? '' : 'about '.implode(', ', $this->topics).', or ').
            'if the question is outside the scope of the context you have.';
    }

    protected function properties(): array
    {
        return [
            new ToolProperty(
                'search_query',
                PropertyType::STRING,
                'The search query to perform web search.',
                true
            ),
            new ToolProperty(
                'topic',
                PropertyType::STRING,
                'Explicit the topic you want to perform the web search on.',
                false,
                ['general', 'news', 'finance']
            ),
            new ToolProperty(
                'time_range',
                PropertyType::STRING,
                'How far back to search for relevant contents.',
                false,
                ['day', 'week', 'month', 'year']
            ),
            new ToolProperty(
                'days',
                PropertyType::INTEGER,
                'Filter search results for a certain range of days up to today.',
                false,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     * @throws \NeuronAI\Exceptions\HttpException
     */
    public function __invoke(
        string $search_query,
        ?string $topic = null,
        ?string $time_range = null,
        ?int $days = null,
    ): array {
        $topic ??= 'general';
        $time_range ??= 'day';
        $days ??= 7;

        $result = $this->getClient()->request(HttpRequest::post('search', array_merge(
            ['topic' => $topic, 'time_range' => $time_range, 'days' => $days],
            $this->options,
            ['query' => $search_query]
        )));

        $result = $result->json();

        return [
            'answer' => $result['answer'],
            'images' => $result['images'] ?? [],
            'results' => array_map(fn (array $item): array => [
                'title' => $item['title'],
                'url' => $item['url'],
                'content' => $item['content'],
            ], $result['results']),
        ];
    }

    public function withOptions(array $options): self
    {
        $this->options = $options;
        return $this;
    }
}
