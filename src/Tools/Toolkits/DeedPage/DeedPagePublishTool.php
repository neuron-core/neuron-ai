<?php

declare(strict_types=1);

namespace NeuronAI\Tools\Toolkits\DeedPage;

use JsonException;
use NeuronAI\Exceptions\HttpException;
use NeuronAI\HttpClient\Curl\CurlHttpClient;
use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\HttpClient\HttpRequest;
use NeuronAI\HttpClient\HttpResponse;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolOutput;
use NeuronAI\Tools\ToolProperty;

use function in_array;
use function is_string;
use function preg_match;
use function trim;

/**
 * Publishes an HTML page to deed.page and returns its public URL.
 *
 * @method static static make(?string $token = null, ?HttpClientInterface $httpClient = null)
 */
class DeedPagePublishTool extends Tool
{
    /** Errors the model can act on (pick another slug, publish less, wait); anything else is raised. */
    protected const RECOVERABLE_STATUSES = [400, 403, 409, 413, 422, 429];

    protected HttpClientInterface $httpClient;

    protected string $url = 'https://deed.page/v1/sites';

    protected string $name = 'publish_web_page';

    protected ?string $description = 'Publish a complete HTML document as a public web page and return its URL. '.
        'Publishing again with the same slug replaces that page. '.
        'Anyone with the URL can open it, so never include secrets or private data.';

    /**
     * @param string|null $token deed.page owner token. Without one, the first publish creates an owner;
     *                           its token is kept on this tool (see getToken()) and never shown to the model.
     */
    public function __construct(protected ?string $token = null, ?HttpClientInterface $httpClient = null)
    {
        $this->httpClient = $httpClient ?? new CurlHttpClient();
    }

    protected function properties(): array
    {
        return [
            new ToolProperty(
                'html',
                PropertyType::STRING,
                'The full HTML document to publish, from <!doctype html> to </html>, with any CSS and JavaScript inline.',
                true
            ),
            new ToolProperty(
                'slug',
                PropertyType::STRING,
                'Optional page name, used as the subdomain: 1 to 63 lowercase letters, digits and hyphens, not starting or ending with a hyphen. Leave it out for a random one.',
                false
            ),
        ];
    }

    /**
     * @return array<string, mixed>|ToolOutput
     * @throws HttpException
     */
    public function __invoke(string $html, ?string $slug = null): array|ToolOutput
    {
        if (trim($html) === '') {
            return ToolOutput::error('The page is empty: pass the HTML document to publish.');
        }

        if ($slug !== null && preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/', $slug) !== 1) {
            return ToolOutput::error('Invalid slug: use 1 to 63 lowercase letters, digits and hyphens, not starting or ending with a hyphen.');
        }

        $body = ['files' => ['index.html' => $html]];
        if ($slug !== null) {
            $body['slug'] = $slug;
        }

        $headers = [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'X-Client' => 'neuron-ai',
        ];
        if ($this->token !== null && $this->token !== '') {
            $headers['Authorization'] = 'Bearer '.$this->token;
        }

        try {
            $response = $this->httpClient->request(HttpRequest::post($this->url, $body, $headers));
        } catch (HttpException $exception) {
            if (!$exception->response instanceof HttpResponse || !in_array($exception->response->statusCode, self::RECOVERABLE_STATUSES, true)) {
                throw $exception;
            }

            $error = $this->decode($exception->response);
            // A first publish over the size limit still creates the owner
            $this->keepToken($error);

            return ToolOutput::error(
                'deed.page did not publish the page ('.($error['code'] ?? 'HTTP '.$exception->response->statusCode).'): '.
                ($error['message'] ?? 'no reason given').
                (is_string($error['suggestion'] ?? null) ? ' Suggestion: '.$error['suggestion'] : '')
            );
        }

        $result = $this->decode($response);
        $this->keepToken($result);

        // Only what the model needs; the owner token never goes back into the conversation
        return [
            'url' => $result['url'] ?? null,
            'slug' => $result['slug'] ?? null,
            'version' => $result['version'] ?? null,
        ];
    }

    /**
     * The owner token in use, including one created by the first publish of this run.
     * Store it privately to update the same pages later.
     */
    public function getToken(): ?string
    {
        return $this->token;
    }

    /**
     * @param array<string, mixed> $result
     */
    protected function keepToken(array $result): void
    {
        if (($this->token === null || $this->token === '') && is_string($result['token'] ?? null) && $result['token'] !== '') {
            $this->token = $result['token'];
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function decode(HttpResponse $response): array
    {
        try {
            return $response->json();
        } catch (JsonException) {
            return [];
        }
    }
}
