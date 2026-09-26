<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools;

use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolCall;
use PHPUnit\Framework\TestCase;

use function json_decode;

class UnencodableResultTest extends TestCase
{
    public function test_a_tool_returning_invalid_utf8_in_an_array_settles_with_a_json_result(): void
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

    public function test_a_call_result_with_invalid_utf8_is_not_silently_emptied(): void
    {
        $call = ToolCall::make('read_blob')->setResult(['id' => 1, 'payload' => "\xB1\xFF"]);

        $this->assertSame(['id' => 1, 'payload' => "\u{FFFD}\u{FFFD}"], json_decode((string) $call->getResult(), true));
    }
}
