<?php

declare(strict_types=1);

namespace NeuronAI\Tests\HttpClient;

use Closure;
use NeuronAI\HttpClient\Curl\CurlHeaderCollector;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function strlen;

class CurlHeaderCollectorTest extends TestCase
{
    public function test_collects_the_status_and_headers_of_a_response(): void
    {
        $collector = $this->ingest(
            "HTTP/1.1 201 Created\r\n",
            "Content-Type: application/json\r\n",
            "Location: https://api.example.com/items/1?x=a:b\r\n",
            "\r\n",
        );

        $this->assertSame(201, $collector->getStatusCode());
        $this->assertSame([
            'Content-Type' => ['application/json'],
            // Only the first colon separates the name from the value.
            'Location' => ['https://api.example.com/items/1?x=a:b'],
        ], $collector->getHeaders());
        $this->assertTrue($collector->isComplete());
    }

    public function test_repeated_headers_keep_every_value_in_order(): void
    {
        $collector = $this->ingest(
            "HTTP/1.1 200 OK\r\n",
            "Set-Cookie: first=1\r\n",
            "Set-Cookie: second=2\r\n",
            "\r\n",
        );

        $this->assertSame(['Set-Cookie' => ['first=1', 'second=2']], $collector->getHeaders());
    }

    public function test_values_are_trimmed_and_lines_without_a_colon_are_ignored(): void
    {
        $collector = $this->ingest(
            "HTTP/2 200\r\n",
            "X-Padded:    spaced value   \r\n",
            "garbage line without separator\r\n",
            "X-Empty:\r\n",
            "\r\n",
        );

        $this->assertSame(200, $collector->getStatusCode());
        $this->assertSame(['X-Padded' => ['spaced value'], 'X-Empty' => ['']], $collector->getHeaders());
    }

    public function test_headers_are_incomplete_until_the_blank_line(): void
    {
        $collector = $this->ingest("HTTP/1.1 200 OK\r\n", "Content-Type: text/plain\r\n");

        $this->assertFalse($collector->isComplete());
    }

    public function test_a_redirect_block_is_replaced_by_the_final_response(): void
    {
        $collector = $this->ingest(
            "HTTP/1.1 302 Found\r\n",
            "Location: /final\r\n",
            "X-Redirect-Only: yes\r\n",
            "\r\n",
        );

        // curl is about to follow the redirect: these are not the final headers.
        $this->assertFalse($collector->isComplete());
        $this->assertSame(302, $collector->getStatusCode());

        $this->ingestInto(
            $collector,
            "HTTP/1.1 200 OK\r\n",
            "Content-Type: application/json\r\n",
            "\r\n",
        );

        $this->assertTrue($collector->isComplete());
        $this->assertSame(200, $collector->getStatusCode());
        $this->assertSame(['Content-Type' => ['application/json']], $collector->getHeaders());
    }

    /**
     * @return iterable<string, array{int, bool}>
     */
    public static function finalStatuses(): iterable
    {
        yield '100 is informational' => [100, false];
        yield '103 is informational' => [103, false];
        yield '200 is final' => [200, true];
        yield '299 is final' => [299, true];
        yield '300 awaits a redirect' => [300, false];
        yield '399 awaits a redirect' => [399, false];
        yield '400 is final' => [400, true];
        yield '503 is final' => [503, true];
    }

    #[DataProvider('finalStatuses')]
    public function test_a_header_block_is_final_unless_it_is_informational_or_redirects(int $status, bool $final): void
    {
        $collector = $this->ingest("HTTP/1.1 {$status} Reason\r\n", "\r\n");

        $this->assertSame($final, $collector->isComplete());
    }

    public function test_a_status_line_without_a_code_yields_zero(): void
    {
        $collector = $this->ingest("HTTP/1.1\r\n", "\r\n");

        $this->assertSame(0, $collector->getStatusCode());
    }

    public function test_every_line_reports_its_full_length_as_curl_requires(): void
    {
        $collector = $this->collector();

        // Returning fewer bytes than received makes curl abort the transfer.
        foreach (["HTTP/1.1 200 OK\r\n", "X-Header:  value \r\n", "no separator\r\n", "\r\n", ''] as $line) {
            $this->assertSame(strlen($line), $collector->ingestLine($line));
        }
    }

    public function test_a_refused_redirect_aborts_the_transfer_before_curl_follows_it(): void
    {
        $collector = $this->collector(fn (string $location): bool => $location !== 'https://evil.example/collect');

        $this->assertSame(strlen("HTTP/1.1 302 Found\r\n"), $collector->ingestLine("HTTP/1.1 302 Found\r\n"));
        $this->assertSame(0, $collector->ingestLine("Location: https://evil.example/collect\r\n"));
        $this->assertSame('refused a redirect to another origin: https://evil.example/collect', $collector->getRefusal());
        $this->assertSame([], $collector->getHeaders());
    }

    public function test_only_a_redirect_location_is_judged(): void
    {
        $collector = $this->collector(fn (string $location): bool => false);

        $this->ingestInto($collector, "HTTP/1.1 201 Created\r\n", "Location: https://elsewhere.example/items/1\r\n", "\r\n");

        $this->assertNull($collector->getRefusal());
        $this->assertSame(['Location' => ['https://elsewhere.example/items/1']], $collector->getHeaders());
    }

    /**
     * @param (Closure(string): bool)|null $allowsRedirectTo
     */
    protected function collector(?Closure $allowsRedirectTo = null): CurlHeaderCollector
    {
        return new CurlHeaderCollector($allowsRedirectTo ?? fn (string $location): bool => true);
    }

    protected function ingest(string ...$lines): CurlHeaderCollector
    {
        $collector = $this->collector();
        $this->ingestInto($collector, ...$lines);

        return $collector;
    }

    protected function ingestInto(CurlHeaderCollector $collector, string ...$lines): void
    {
        foreach ($lines as $line) {
            $collector->ingestLine($line);
        }
    }
}
