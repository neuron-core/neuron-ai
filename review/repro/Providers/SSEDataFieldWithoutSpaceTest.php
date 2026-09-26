<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Providers;

use GuzzleHttp\Psr7\Utils;
use NeuronAI\HttpClient\Guzzle\GuzzleStream;
use NeuronAI\Providers\SSEParser;
use PHPUnit\Framework\TestCase;

class SSEDataFieldWithoutSpaceTest extends TestCase
{
    public function test_data_field_without_the_optional_space_is_decoded(): void
    {
        $this->assertSame(
            ['type' => 'ping'],
            SSEParser::parseNextSSEEvent(new GuzzleStream(Utils::streamFor("data:{\"type\":\"ping\"}\n"))),
        );
    }

    public function test_data_field_with_the_space_is_decoded(): void
    {
        $this->assertSame(
            ['type' => 'ping'],
            SSEParser::parseNextSSEEvent(new GuzzleStream(Utils::streamFor("data: {\"type\":\"ping\"}\n"))),
        );
    }
}
