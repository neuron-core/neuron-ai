<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Providers;

use GuzzleHttp\Psr7\Utils;
use NeuronAI\HttpClient\Guzzle\GuzzleStream;
use NeuronAI\Providers\SSEParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SSEParserScalarLineTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function scalar_lines(): array
    {
        return [
            'bare number keep-alive' => ["1\n"],
            'bare json string' => ["\"ping\"\n"],
            'bare boolean' => ["true\n"],
            'data line with number' => ["data: 1\n"],
            'data line with json string' => ["data: \"ping\"\n"],
            'data line with null' => ["data: null\n"],
        ];
    }

    #[DataProvider('scalar_lines')]
    public function test_json_scalars_are_not_returned_as_events(string $line): void
    {
        $this->assertNull(SSEParser::parseNextSSEEvent(new GuzzleStream(Utils::streamFor($line))));
    }
}
