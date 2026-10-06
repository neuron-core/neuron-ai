<?php

declare(strict_types=1);

namespace NeuronAI\Tools\Toolkits\Jina;

use NeuronAI\Exceptions\HttpException;
use NeuronAI\HttpClient\Curl\CurlHttpClient;
use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\HttpClient\HttpRequest;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolOutput;
use NeuronAI\Tools\ToolProperty;

use function filter_var;
use function json_decode;
use function preg_match;

use const FILTER_VALIDATE_URL;

/**
 * @method static static make(string $key, ?HttpClientInterface $httpClient = null)
 */
class JinaUrlReader extends Tool
{
    protected HttpClientInterface $httpClient;

    protected string $name = 'url_reader';
    protected ?string $description = 'Read a single web page and return its content in Markdown format. '.
        'Use it when you already have the address of the page: a URL the user gave you, or a search result whose description is not enough to answer. '.
        'It reads that page only, without following its links.';

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

        try {
            $response = $this->httpClient->request(HttpRequest::post('https://r.jina.ai/', [
                'url' => $url,
            ], [
                'Authorization' => 'Bearer '.$this->key,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
                'X-Return-Format' => 'Markdown',
            ]));
        } catch (HttpException $exception) {
            // Jina answers 422 for a page it could not load, a dead link included: a routine
            // outcome the model can work around. Any other failure is not an answer about the page.
            if ($exception->response?->statusCode !== 422) {
                throw $exception;
            }

            $reason = json_decode($exception->response->body, true)['message'] ?? $exception->response->body;

            return ToolOutput::error("Jina could not read '{$url}': {$reason}");
        }

        return $response->body;
    }
}
