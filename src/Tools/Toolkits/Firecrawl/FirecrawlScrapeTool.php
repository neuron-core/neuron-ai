<?php

declare(strict_types=1);

namespace NeuronAI\Tools\Toolkits\Firecrawl;

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
class FirecrawlScrapeTool extends Tool
{
    use HandleFirecrawlClient;

    protected string $name = 'url_reader';

    protected ?string $description = 'Read a single web page and return its content in Markdown format. '.
        'Use it when you already have the address of the page: a URL the user gave you, or a search result whose description is not enough to answer. '.
        'It reads that page only, without following its links.';

    protected array $options = [
        'onlyMainContent' => true,
    ];

    /**
     * @param string $key Firecrawl API key.
     */
    public function __construct(protected string $key, ?HttpClientInterface $httpClient = null)
    {
        $this->httpClient = $httpClient ?? new CurlHttpClient();
    }

    protected function properties(): array
    {
        return [
            new ToolProperty(
                'url',
                PropertyType::STRING,
                'The absolute http or https URL of the page to read.',
                true
            ),
        ];
    }

    public function __invoke(string $url): string|ToolOutput
    {
        if (preg_match('~^https?://~i', $url) !== 1 || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return ToolOutput::error('Invalid URL: an absolute http or https URL is required.');
        }

        // The tool hands the model Markdown, so options can tune the scrape but not replace the format
        $result = $this->post('scrape', array_merge(
            $this->options,
            ['url' => $url, 'formats' => ['markdown']]
        ));

        $result = $result->json();

        // An unreachable host comes back as success: false, a missing page as the error page's content
        $statusCode = $result['data']['metadata']['statusCode'] ?? 200;
        if ($statusCode >= 400) {
            return ToolOutput::error("'{$url}' answered with HTTP {$statusCode}.");
        }

        return $result['data']['markdown'] ?? ToolOutput::error(
            "Firecrawl could not read '{$url}': " . ($result['error'] ?? 'no content returned')
        );
    }

    public function withOptions(array $options): self
    {
        $this->options = $options;
        return $this;
    }
}
