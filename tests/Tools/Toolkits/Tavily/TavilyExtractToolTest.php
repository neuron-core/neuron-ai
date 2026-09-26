<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools\Toolkits\Tavily;

use GuzzleHttp\Psr7\Response;
use NeuronAI\Exceptions\ToolException;
use NeuronAI\Tests\Support\RecordsHttpRequests;
use NeuronAI\Tools\Toolkits\Tavily\TavilyExtractTool;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function json_decode;
use function json_encode;

class TavilyExtractToolTest extends TestCase
{
    use RecordsHttpRequests;

    public function test_posts_the_url_as_a_single_item_list(): void
    {
        $tool = new TavilyExtractTool('tavily-key', $this->recordingClient($this->extractResponse()));

        $tool->setInputs(['url' => 'https://example.com/a?b=1&c=2'])->execute();

        $this->assertSame(['POST https://api.tavily.com/extract'], $this->sentTargets());
        $this->assertSame('Bearer tavily-key', $this->sentRequests[0]['request']->getHeaderLine('Authorization'));
        $this->assertSame(['urls' => ['https://example.com/a?b=1&c=2']], $this->sentBody());
    }

    public function test_returns_the_first_extracted_result(): void
    {
        $tool = new TavilyExtractTool('tavily-key', $this->recordingClient(new Response(200, [], json_encode([
            'results' => [['url' => 'https://example.com', 'raw_content' => '# Page', 'images' => []]],
            'failed_results' => [],
            'response_time' => 0.3,
        ]))));

        $this->assertSame(['url' => 'https://example.com', 'raw_content' => '# Page', 'images' => []], $tool('https://example.com'));
    }

    public function test_options_are_merged_but_cannot_override_the_url(): void
    {
        $tool = (new TavilyExtractTool('tavily-key', $this->recordingClient($this->extractResponse())))
            ->withOptions(['extract_depth' => 'advanced', 'urls' => ['https://attacker.example']]);

        $tool('https://example.com');

        $this->assertSame(['extract_depth' => 'advanced', 'urls' => ['https://example.com']], $this->sentBody());
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
        ];
    }

    #[DataProvider('invalidUrls')]
    public function test_an_invalid_url_is_rejected_before_any_request(string $url): void
    {
        $tool = new TavilyExtractTool('tavily-key', $this->recordingClient());

        try {
            $tool($url);
            $this->fail("Expected a ToolException for '{$url}'.");
        } catch (ToolException $exception) {
            $this->assertSame('Invalid URL.', $exception->getMessage());
        }

        $this->assertSame([], $this->sentRequests);
    }

    protected function extractResponse(): Response
    {
        return new Response(200, [], json_encode(['results' => [['url' => 'https://example.com', 'raw_content' => 'Page']]]));
    }

    /**
     * @return array<string, mixed>
     */
    protected function sentBody(): array
    {
        return json_decode((string) $this->sentRequests[0]['request']->getBody(), true);
    }
}
