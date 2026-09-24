<?php

declare(strict_types=1);

namespace NeuronAI\Tools\Toolkits\Tavily;

use NeuronAI\Exceptions\ToolException;
use NeuronAI\HttpClient\Curl\CurlHttpClient;
use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\Tool;

use function array_merge;
use function filter_var;

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

    public function __invoke(string $url): array
    {
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            throw new ToolException('Invalid URL.');
        }

        $result = $this->post('extract', array_merge(
            $this->options,
            ['urls' => [$url]]
        ));

        $result = $result->json();

        return $result['results'][0];
    }

    public function withOptions(array $options): self
    {
        $this->options = $options;
        return $this;
    }
}
