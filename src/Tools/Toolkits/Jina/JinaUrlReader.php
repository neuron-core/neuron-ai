<?php

declare(strict_types=1);

namespace NeuronAI\Tools\Toolkits\Jina;

use NeuronAI\Exceptions\ToolException;
use NeuronAI\HttpClient\Curl\CurlHttpClient;
use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\HttpClient\HttpRequest;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;

use function filter_var;

use const FILTER_VALIDATE_URL;

/**
 * @method static static make(string $key, ?HttpClientInterface $httpClient = null)
 */
class JinaUrlReader extends Tool
{
    protected HttpClientInterface $httpClient;

    protected string $name = 'url_reader';
    protected ?string $description = 'Get the content of a URL in markdown format.';

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

    public function __invoke(string $url): string
    {
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            throw new ToolException('Invalid URL.');
        }

        $response = $this->httpClient->request(HttpRequest::post('https://r.jina.ai/', [
            'url' => $url,
        ], [
            'Authorization' => 'Bearer '.$this->key,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'X-Return-Format' => 'Markdown',
        ]));

        return $response->body;
    }
}
