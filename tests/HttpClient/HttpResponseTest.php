<?php

declare(strict_types=1);

namespace NeuronAI\Tests\HttpClient;

use NeuronAI\HttpClient\HttpResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class HttpResponseTest extends TestCase
{
    public function test_json_decodes_the_body_into_an_associative_array(): void
    {
        $response = new HttpResponse(200, '{"data":{"items":[1,2],"name":"café"}}');

        $this->assertSame(['data' => ['items' => [1, 2], 'name' => 'café']], $response->json());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function undecodableBodies(): iterable
    {
        yield 'empty body' => [''];
        yield 'invalid json' => ['{"unterminated":'];
        yield 'html error page' => ['<html><body>Bad Gateway</body></html>'];
        yield 'json null' => ['null'];
    }

    #[DataProvider('undecodableBodies')]
    public function test_json_of_a_body_without_a_json_value_is_empty(string $body): void
    {
        $this->assertSame([], (new HttpResponse(502, $body))->json());
    }

    /**
     * @return iterable<string, array{int, bool}>
     */
    public static function statuses(): iterable
    {
        yield 'informational' => [101, false];
        yield 'below 2xx' => [199, false];
        yield 'ok' => [200, true];
        yield 'no content' => [204, true];
        yield 'last 2xx' => [299, true];
        yield 'redirect' => [300, false];
        yield 'client error' => [404, false];
        yield 'server error' => [500, false];
    }

    #[DataProvider('statuses')]
    public function test_only_2xx_statuses_are_successful(int $status, bool $successful): void
    {
        $this->assertSame($successful, (new HttpResponse($status, ''))->isSuccessful());
    }

    public function test_header_lookup_is_case_insensitive(): void
    {
        $response = new HttpResponse(200, '', ['Mcp-Session-Id' => 'abc']);

        $this->assertSame('abc', $response->header('mcp-session-id'));
        $this->assertSame('abc', $response->header('MCP-SESSION-ID'));
    }

    public function test_header_returns_the_first_of_multiple_values(): void
    {
        $response = new HttpResponse(200, '', ['Set-Cookie' => ['first=1', 'second=2']]);

        $this->assertSame('first=1', $response->header('set-cookie'));
    }

    public function test_header_without_values_or_absent_is_null(): void
    {
        $response = new HttpResponse(200, '', ['X-Empty' => []]);

        $this->assertNull($response->header('X-Empty'));
        $this->assertNull($response->header('X-Missing'));
    }

    public function test_header_keeps_an_empty_string_value(): void
    {
        $response = new HttpResponse(200, '', ['X-Blank' => '']);

        $this->assertSame('', $response->header('x-blank'));
    }
}
