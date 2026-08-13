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
class TavilyExtractTool extends Tool
{
    use HandleTavilyClient;

    protected string $name = 'url_reader';

    protected ?string $description = 'Get the content of a URL in markdown format.';

    protected array $options = [];

    /**
     * @param string $key Tavily API key.
     */
    public function __construct(protected string $key)
    {
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

        $result = $this->getClient()->request(HttpRequest::post('extract', array_merge(
            $this->options,
            ['urls' => [$url]]
        )));

        $result = $result->json();

        return $result['results'][0];
    }

    public function withOptions(array $options): self
    {
        $this->options = $options;
        return $this;
    }
}
