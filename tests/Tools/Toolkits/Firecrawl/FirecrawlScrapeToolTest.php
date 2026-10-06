<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools\Toolkits\Firecrawl;

use GuzzleHttp\Psr7\Response;
use NeuronAI\Exceptions\HttpException;
use NeuronAI\Tests\Support\RecordsHttpRequests;
use NeuronAI\Tests\Support\ToolErrorAssertions;
use NeuronAI\Tools\Toolkits\Firecrawl\FirecrawlScrapeTool;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function json_decode;
use function json_encode;

class FirecrawlScrapeToolTest extends TestCase
{
    use RecordsHttpRequests;
    use ToolErrorAssertions;

    public function test_posts_the_url_asking_for_the_main_content_as_markdown(): void
    {
        $tool = new FirecrawlScrapeTool('firecrawl-key', $this->recordingClient($this->scrapeResponse('# Page')));

        $tool->setInputs(['url' => 'https://example.com/a?b=1&c=2'])->execute();

        $this->assertSame(['POST https://api.firecrawl.dev/v2/scrape'], $this->sentTargets());
        $request = $this->sentRequests[0]['request'];
        $this->assertSame('Bearer firecrawl-key', $request->getHeaderLine('Authorization'));
        $this->assertSame('application/json', $request->getHeaderLine('Content-Type'));
        $this->assertSame([
            'onlyMainContent' => true,
            'url' => 'https://example.com/a?b=1&c=2',
            'formats' => ['markdown'],
            'origin' => 'neuron-ai',
        ], $this->sentBody());
        $this->assertSame('# Page', (string) $tool->getResult());
    }

    public function test_returns_the_page_markdown(): void
    {
        $tool = new FirecrawlScrapeTool('firecrawl-key', $this->recordingClient(new Response(200, [], json_encode([
            'success' => true,
            'data' => [
                'markdown' => "# Neuron\n\nA PHP agent framework.",
                'metadata' => ['title' => 'Neuron', 'sourceURL' => 'https://neuron-ai.dev', 'statusCode' => 200],
            ],
        ]))));

        $this->assertSame("# Neuron\n\nA PHP agent framework.", $tool('https://neuron-ai.dev'));
    }

    public function test_an_unreachable_host_is_an_error_for_the_model(): void
    {
        $tool = new FirecrawlScrapeTool('firecrawl-key', $this->recordingClient(new Response(200, [], json_encode([
            'success' => false,
            'code' => 'SCRAPE_DNS_RESOLUTION_ERROR',
            'error' => 'DNS resolution failed for hostname "missing.example".',
        ]))));

        $this->assertToolError(
            "Firecrawl could not read 'https://missing.example': DNS resolution failed for hostname \"missing.example\".",
            $tool('https://missing.example')
        );
    }

    public function test_a_page_answering_with_an_error_status_is_an_error_for_the_model(): void
    {
        $tool = new FirecrawlScrapeTool('firecrawl-key', $this->recordingClient(new Response(200, [], json_encode([
            'success' => true,
            'data' => ['markdown' => '# Not Found', 'metadata' => ['sourceURL' => 'https://example.com/missing', 'statusCode' => 404]],
        ]))));

        $this->assertToolError("'https://example.com/missing' answered with HTTP 404.", $tool('https://example.com/missing'));
    }

    public function test_no_content_without_a_reason_is_an_error_for_the_model(): void
    {
        $tool = new FirecrawlScrapeTool('firecrawl-key', $this->recordingClient(new Response(200, [], json_encode(['success' => true, 'data' => []]))));

        $this->assertToolError("Firecrawl could not read 'https://example.com': no content returned", $tool('https://example.com'));
    }

    public function test_options_replace_the_defaults_but_cannot_override_the_url_or_the_format(): void
    {
        $tool = (new FirecrawlScrapeTool('firecrawl-key', $this->recordingClient($this->scrapeResponse('Page'))))
            ->withOptions(['waitFor' => 1000, 'url' => 'https://attacker.example', 'formats' => ['html']]);

        $tool('https://example.com');

        $body = $this->sentBody();
        $this->assertSame(1000, $body['waitFor']);
        $this->assertArrayNotHasKey('onlyMainContent', $body);
        $this->assertSame('https://example.com', $body['url']);
        $this->assertSame(['markdown'], $body['formats']);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidUrls(): array
    {
        return [
            'empty' => [''],
            'plain text' => ['example page'],
            'missing scheme' => ['example.com'],
            'scheme only' => ['https://'],
            'local file' => ['file:///etc/passwd'],
            'ftp' => ['ftp://example.com/file'],
        ];
    }

    #[DataProvider('invalidUrls')]
    public function test_an_invalid_url_is_rejected_before_any_request(string $url): void
    {
        $tool = new FirecrawlScrapeTool('firecrawl-key', $this->recordingClient());

        $this->assertToolError('Invalid URL: an absolute http or https URL is required.', $tool($url));

        $this->assertSame([], $this->sentRequests);
    }

    public function test_an_error_response_raises_an_http_exception_without_the_api_key(): void
    {
        $tool = new FirecrawlScrapeTool('secret-firecrawl-key', $this->recordingClient(
            new Response(401, [], '{"success":false,"error":"Unauthorized: Invalid token"}')
        ));

        try {
            $tool('https://example.com');
            $this->fail('Expected an HttpException for a 401 response.');
        } catch (HttpException $exception) {
            $this->assertSame(401, $exception->response?->statusCode);
            $this->assertStringNotContainsString('secret-firecrawl-key', $exception->getMessage());
        }
    }

    protected function scrapeResponse(string $markdown): Response
    {
        return new Response(200, [], json_encode(['success' => true, 'data' => ['markdown' => $markdown]]));
    }

    /**
     * @return array<string, mixed>
     */
    protected function sentBody(): array
    {
        return json_decode((string) $this->sentRequests[0]['request']->getBody(), true);
    }
}
