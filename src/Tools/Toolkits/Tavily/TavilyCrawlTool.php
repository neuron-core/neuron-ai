<?php

declare(strict_types=1);

namespace NeuronAI\Tools\Toolkits\Tavily;

use NeuronAI\HttpClient\Curl\CurlHttpClient;
use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\ToolOutput;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\Tool;

use function array_merge;
use function filter_var;
use function preg_match;

use const FILTER_VALIDATE_URL;

/**
 * @method static static make(string $key, ?HttpClientInterface $httpClient = null)
 */
class TavilyCrawlTool extends Tool
{
    use HandleTavilyClient;

    protected string $name = 'url_crawl';

    protected ?string $description = 'Get the entire website in markdown format.';

    protected array $options = [
        'include_images' => false,
        'allow_external' => false,
    ];

    /**
     * @param string $key Tavily API key.
     */
    public function __construct(
        protected string $key,
        ?HttpClientInterface $httpClient = null,
    ) {
        $this->httpClient = $httpClient ?? new CurlHttpClient();
    }

    protected function properties(): array
    {
        return [
            new ToolProperty(
                'url',
                PropertyType::STRING,
                'The URL to crawl.',
                true
            ),
        ];
    }

    public function __invoke(string $url): array|ToolOutput
    {
        if (preg_match('~^https?://~i', $url) !== 1 || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return ToolOutput::error('Invalid URL: an absolute http or https URL is required.');
        }

        $result = $this->post('crawl', array_merge(
            $this->options,
            ['url' => $url]
        ));

        return $result->json();
    }

    public function withOptions(array $options): self
    {
        $this->options = $options;
        return $this;
    }
}
