<?php

declare(strict_types=1);

namespace NeuronAI\Tools\Toolkits\Firecrawl;

use NeuronAI\HttpClient\Curl\CurlHttpClient;
use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\Tool;

use function array_keys;
use function array_map;
use function array_merge;
use function implode;

/**
 * @method static static make(string $key, array $topics = [], ?HttpClientInterface $httpClient = null)
 */
class FirecrawlSearchTool extends Tool
{
    use HandleFirecrawlClient;

    protected const TIME_RANGES = [
        'day' => 'qdr:d',
        'week' => 'qdr:w',
        'month' => 'qdr:m',
        'year' => 'qdr:y',
    ];

    protected string $name = 'web_search';

    protected ?string $description;

    protected array $options = [
        'limit' => 5,
    ];

    /**
     * @param string $key Firecrawl API key.
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
            'A result describes its page and may not carry its full content.';
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
                'The category of sources to search: news for current events covered by mainstream media, general for anything else. Defaults to general.',
                false,
                ['general', 'news']
            ),
            new ToolProperty(
                'time_range',
                PropertyType::STRING,
                'How far back to search for relevant contents. It applies to general searches, not to news.',
                false,
                array_keys(self::TIME_RANGES)
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
    ): array {
        $source = $topic === 'news' ? 'news' : 'web';

        // Only the filters the model chose: a default range would silently narrow every search
        $filters = ['sources' => [$source]];
        // Firecrawl applies time-based search to web results only
        if ($time_range !== null && $source === 'web') {
            $filters['tbs'] = self::TIME_RANGES[$time_range];
        }

        $result = $this->post('search', array_merge(
            $filters,
            $this->options,
            ['query' => $search_query]
        ));

        $result = $result->json();

        // Firecrawl leaves the source out of the response entirely when nothing matched
        return [
            'results' => array_map(fn (array $item): array => [
                'title' => $item['title'] ?? '',
                'url' => $item['url'],
                'content' => $item['markdown'] ?? $item['description'] ?? $item['snippet'] ?? '',
                ...($source === 'news' ? ['date' => $item['date'] ?? null] : []),
            ], $result['data'][$source] ?? []),
        ];
    }

    public function withOptions(array $options): self
    {
        $this->options = $options;
        return $this;
    }
}
