<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Providers;

use GuzzleHttp\Psr7\Utils;
use JsonException;
use NeuronAI\Exceptions\ProviderException;
use NeuronAI\HttpClient\Guzzle\GuzzleStream;
use NeuronAI\Providers\SSEParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SSEParserTest extends TestCase
{
    protected function stream(string $body): GuzzleStream
    {
        return new GuzzleStream(Utils::streamFor($body));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function data_lines(): array
    {
        return [
            'line feed' => ["data: {\"type\":\"ping\",\"n\":1}\n"],
            'carriage return line feed' => ["data: {\"type\":\"ping\",\"n\":1}\r\n"],
            'trailing spaces' => ["data: {\"type\":\"ping\",\"n\":1}   \n"],
            'last line without terminator' => ['data: {"type":"ping","n":1}'],
        ];
    }

    #[DataProvider('data_lines')]
    public function test_decodes_the_json_payload_of_a_data_line(string $line): void
    {
        $this->assertSame(['type' => 'ping', 'n' => 1], SSEParser::parseNextSSEEvent($this->stream($line)));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function lines_without_payload(): array
    {
        return [
            'blank separator' => ["\n"],
            'carriage return separator' => ["\r\n"],
            'event name' => ["event: message_start\n"],
            'comment keep-alive' => [": ping\n"],
            'event id' => ["id: 42\n"],
            'retry hint' => ["retry: 3000\n"],
            'done sentinel' => ["data: [DONE]\n"],
            'done sentinel with crlf' => ["data: [DONE]\r\n"],
            'end of stream' => [''],
        ];
    }

    #[DataProvider('lines_without_payload')]
    public function test_lines_without_a_json_payload_yield_null(string $line): void
    {
        $this->assertNull(SSEParser::parseNextSSEEvent($this->stream($line)));
    }

    public function test_bare_json_lines_are_decoded_for_newline_delimited_streams(): void
    {
        $this->assertSame(['done' => false], SSEParser::parseNextSSEEvent($this->stream("{\"done\":false}\n")));
    }

    public function test_reads_one_event_per_call_in_order(): void
    {
        $stream = $this->stream("event: a\ndata: {\"n\":1}\n\nevent: b\ndata: {\"n\":2}\n\n");

        $events = [];
        while (! $stream->eof()) {
            if ($event = SSEParser::parseNextSSEEvent($stream)) {
                $events[] = $event;
            }
        }

        $this->assertSame([['n' => 1], ['n' => 2]], $events);
    }

    public function test_preserves_multibyte_payloads(): void
    {
        $this->assertSame(
            ['text' => 'Ciao 👋 — こんにちは'],
            SSEParser::parseNextSSEEvent($this->stream("data: {\"text\":\"Ciao 👋 — こんにちは\"}\n")),
        );
    }

    public function test_malformed_json_in_a_data_line_throws_a_provider_exception(): void
    {
        try {
            SSEParser::parseNextSSEEvent($this->stream("data: {\"type\":\"content_block_delta\",\n"));
            $this->fail('A truncated data payload must not be silently skipped.');
        } catch (ProviderException $exception) {
            $this->assertSame('Streaming error - Syntax error', $exception->getMessage());
            $this->assertInstanceOf(JsonException::class, $exception->getPrevious());
        }
    }
}
