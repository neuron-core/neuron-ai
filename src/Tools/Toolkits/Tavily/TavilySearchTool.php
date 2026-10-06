<?php

declare(strict_types=1);

namespace NeuronAI\Tools\Toolkits\Tavily;

use NeuronAI\HttpClient\Curl\CurlHttpClient;
use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\Tool;

use function array_filter;
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
        ?HttpClientInterface $httpClient = null,
    ) {
        $this->httpClient = $httpClient ?? new CurlHttpClient();
        $this->description = 'Search the web and return the most relevant pages, each with its title, URL and a short description of its content. '.
            'Use it '.($this->topics === [] ? '' : 'for questions about '.implode(', ', $this->topics).', or ').
            'when the question is outside the scope of the context you have. '.
            'A result describes its page and does not carry its full content.';
    }

    protected function properties(): array
    {
        return [
            new ToolProperty(
                'search_query',
                PropertyType::STRING,
                'A concise query about a single subject, written as you would type it in a search engine. Run a separate search for each subject of a complex question.',
                true
            ),
            new ToolProperty(
                'topic',
                PropertyType::STRING,
                'The category of sources to search: news for current events covered by mainstream media, finance for financial information, general for anything else. Defaults to general.',
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
        // Only the filters the model chose: a default range would silently narrow every search
        $filters = array_filter(
            ['topic' => $topic ?? 'general', 'time_range' => $time_range, 'days' => $days],
            fn (string|int|null $filter): bool => $filter !== null
        );

        $result = $this->post('search', array_merge(
            $filters,
            $this->options,
            ['query' => $search_query]
        ));

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
