<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools;

use JsonException;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolCall;
use PHPUnit\Framework\TestCase;

use function json_decode;

use const INF;

class ToolResultEncodingTest extends TestCase
{
    public function test_invalid_utf8_in_a_tool_array_result_is_substituted(): void
    {
        $tool = new class () extends Tool {
            protected string $name = 'read_blob';

            public function __invoke(): array
            {
                return ['id' => 1, 'payload' => "\xB1\xFF"];
            }
        };

        $tool->execute();

        $this->assertSame(['id' => 1, 'payload' => "\u{FFFD}\u{FFFD}"], json_decode((string) $tool->getResult(), true));
    }

    public function test_invalid_utf8_in_a_call_array_result_is_substituted(): void
    {
        $call = ToolCall::make('read_blob')->setResult(['id' => 1, 'payload' => "\xB1\xFF"]);

        $this->assertSame(['id' => 1, 'payload' => "\u{FFFD}\u{FFFD}"], json_decode((string) $call->getResult(), true));
    }

    public function test_an_unencodable_tool_array_result_throws_a_json_exception(): void
    {
        $tool = new class () extends Tool {
            protected string $name = 'ratio';

            public function __invoke(): array
            {
                return ['ratio' => INF];
            }
        };

        $this->expectException(JsonException::class);

        $tool->execute();
    }

    public function test_an_unencodable_call_array_result_throws_a_json_exception(): void
    {
        $this->expectException(JsonException::class);

        ToolCall::make('ratio')->setResult(['ratio' => INF]);
    }
}
