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
class TavilyExtractTool extends Tool
{
    use HandleTavilyClient;

    protected string $name = 'url_reader';

    protected ?string $description = 'Get the content of a URL in markdown format.';

    protected array $options = [];

    /**
     * @param string $key Tavily API key.
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
                'The URL to read.',
                true
            ),
        ];
    }

    public function __invoke(string $url): array|ToolOutput
    {
        if (preg_match('~^https?://~i', $url) !== 1 || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return ToolOutput::error('Invalid URL: an absolute http or https URL is required.');
        }

        $result = $this->post('extract', array_merge(
            $this->options,
            ['urls' => [$url]]
        ));

        $result = $result->json();

        // An unreachable page is reported under failed_results: a routine outcome the model can work around
        return $result['results'][0] ?? ToolOutput::error(
            "Tavily could not extract '{$url}': " . ($result['failed_results'][0]['error'] ?? 'no content returned') . '.'
        );
    }

    public function withOptions(array $options): self
    {
        $this->options = $options;
        return $this;
    }
}
