<?php

declare(strict_types=1);

namespace NeuronAI\Tools\Toolkits\Tavily;

use NeuronAI\Exceptions\ToolException;
use NeuronAI\HttpClient\HttpRequest;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\Tool;

use function array_merge;
use function filter_var;

use const FILTER_VALIDATE_URL;

/**
 * @method static static make(string $key)
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
    ) {
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

    public function __invoke(string $url): array
    {
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            throw new ToolException('Invalid URL.');
        }

        $result = $this->getClient()->request(HttpRequest::post('crawl', array_merge(
            $this->options,
            ['url' => $url]
        )));

        return $result->json();
    }

    public function withOptions(array $options): self
    {
        $this->options = $options;
        return $this;
    }
}
