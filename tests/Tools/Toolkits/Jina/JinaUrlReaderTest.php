<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools\Toolkits\Jina;

use GuzzleHttp\Psr7\Response;
use NeuronAI\Exceptions\HttpException;
use NeuronAI\Exceptions\ToolException;
use NeuronAI\Tests\Support\RecordsHttpRequests;
use NeuronAI\Tools\Toolkits\Jina\JinaUrlReader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function json_decode;

class JinaUrlReaderTest extends TestCase
{
    use RecordsHttpRequests;

    public function test_posts_the_url_as_json_asking_for_markdown(): void
    {
        $tool = new JinaUrlReader('jina-key', $this->recordingClient(new Response(200, [], '# Page')));

        $tool->setInputs(['url' => 'https://example.com/docs'])->execute();

        $this->assertSame(['POST https://r.jina.ai/'], $this->sentTargets());
        $request = $this->sentRequests[0]['request'];
        $this->assertSame('Bearer jina-key', $request->getHeaderLine('Authorization'));
        $this->assertSame('application/json', $request->getHeaderLine('Content-Type'));
        $this->assertSame('application/json', $request->getHeaderLine('Accept'));
        $this->assertSame('Markdown', $request->getHeaderLine('X-Return-Format'));
        $this->assertSame(['url' => 'https://example.com/docs'], json_decode((string) $request->getBody(), true));
        $this->assertSame('# Page', (string) $tool->getResult());
    }

    public function test_the_target_url_is_forwarded_untouched_in_the_body(): void
    {
        $url = 'https://example.com/search?q=a%20b&lang=en#results';
        $tool = new JinaUrlReader('jina-key', $this->recordingClient(new Response(200, [], 'ok')));

        $tool($url);

        $this->assertSame(['POST https://r.jina.ai/'], $this->sentTargets());
        $this->assertSame(['url' => $url], json_decode((string) $this->sentRequests[0]['request']->getBody(), true));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidUrls(): array
    {
        return [
            'empty' => [''],
            'plain text' => ['read the neuron docs'],
            'missing scheme' => ['example.com/page'],
            'scheme only' => ['https://'],
            'whitespace in host' => ['https://exa mple.com'],
        ];
    }

    #[DataProvider('invalidUrls')]
    public function test_an_invalid_url_is_rejected_before_any_request(string $url): void
    {
        $tool = new JinaUrlReader('jina-key', $this->recordingClient());

        try {
            $tool($url);
            $this->fail("Expected a ToolException for '{$url}'.");
        } catch (ToolException $exception) {
            $this->assertSame('Invalid URL.', $exception->getMessage());
        }

        $this->assertSame([], $this->sentRequests);
    }

    public function test_an_error_response_raises_an_http_exception_without_the_api_key(): void
    {
        $tool = new JinaUrlReader('secret-jina-key', $this->recordingClient(new Response(422, [], 'Unprocessable')));

        try {
            $tool('https://example.com');
            $this->fail('Expected an HttpException for a 422 response.');
        } catch (HttpException $exception) {
            $this->assertSame(422, $exception->response?->statusCode);
            $this->assertStringNotContainsString('secret-jina-key', $exception->getMessage());
        }
    }
}
