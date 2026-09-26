<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools\Toolkits\Tavily;

use GuzzleHttp\Psr7\Response;
use NeuronAI\Exceptions\ToolException;
use NeuronAI\Tests\Support\RecordsHttpRequests;
use NeuronAI\Tools\Toolkits\Tavily\TavilyCrawlTool;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function json_decode;
use function json_encode;

class TavilyCrawlToolTest extends TestCase
{
    use RecordsHttpRequests;

    public function test_crawls_without_images_or_external_links_by_default(): void
    {
        $tool = new TavilyCrawlTool('tavily-key', $this->recordingClient($this->crawlResponse()));

        $tool->setInputs(['url' => 'https://example.com'])->execute();

        $this->assertSame(['POST https://api.tavily.com/crawl'], $this->sentTargets());
        $this->assertSame('Bearer tavily-key', $this->sentRequests[0]['request']->getHeaderLine('Authorization'));
        $this->assertSame(['include_images' => false, 'allow_external' => false, 'url' => 'https://example.com'], $this->sentBody());
    }

    public function test_returns_the_decoded_crawl_payload(): void
    {
        $payload = ['base_url' => 'https://example.com', 'results' => [['url' => 'https://example.com/a', 'raw_content' => 'A']]];
        $tool = new TavilyCrawlTool('tavily-key', $this->recordingClient(new Response(200, [], json_encode($payload))));

        $this->assertSame($payload, $tool('https://example.com'));
    }

    public function test_options_replace_the_defaults_but_cannot_override_the_url(): void
    {
        $tool = (new TavilyCrawlTool('tavily-key', $this->recordingClient($this->crawlResponse())))
            ->withOptions(['max_depth' => 2, 'url' => 'https://attacker.example']);

        $tool('https://example.com');

        $this->assertSame(['max_depth' => 2, 'url' => 'https://example.com'], $this->sentBody());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidUrls(): array
    {
        return [
            'empty' => [''],
            'plain text' => ['not a url'],
            'missing scheme' => ['example.com'],
            'scheme only' => ['https://'],
        ];
    }

    #[DataProvider('invalidUrls')]
    public function test_an_invalid_url_is_rejected_before_any_request(string $url): void
    {
        $tool = new TavilyCrawlTool('tavily-key', $this->recordingClient());

        try {
            $tool($url);
            $this->fail("Expected a ToolException for '{$url}'.");
        } catch (ToolException $exception) {
            $this->assertSame('Invalid URL.', $exception->getMessage());
        }

        $this->assertSame([], $this->sentRequests);
    }

    protected function crawlResponse(): Response
    {
        return new Response(200, [], json_encode(['base_url' => 'https://example.com', 'results' => []]));
    }

    /**
     * @return array<string, mixed>
     */
    protected function sentBody(): array
    {
        return json_decode((string) $this->sentRequests[0]['request']->getBody(), true);
    }
}
