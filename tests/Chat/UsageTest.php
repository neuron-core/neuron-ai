<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Chat;

use NeuronAI\Chat\Messages\Usage;
use PHPUnit\Framework\TestCase;

class UsageTest extends TestCase
{
    public function test_cached_and_reasoning_default_to_zero(): void
    {
        $usage = new Usage(100, 20);

        $this->assertSame(0, $usage->cachedInputTokens);
        $this->assertSame(0, $usage->reasoningTokens);
    }

    public function test_cached_and_reasoning_are_stored(): void
    {
        $usage = new Usage(100, 20, 40, 12);

        $this->assertSame(100, $usage->inputTokens);
        $this->assertSame(20, $usage->outputTokens);
        $this->assertSame(40, $usage->cachedInputTokens);
        $this->assertSame(12, $usage->reasoningTokens);
    }

    public function test_get_total_covers_input_and_output_only(): void
    {
        $usage = new Usage(100, 20, 40, 12);

        $this->assertSame(120, $usage->getTotal());
    }

    public function test_json_serialize_exposes_all_four_fields(): void
    {
        $usage = new Usage(100, 20, 40, 12);

        $this->assertSame([
            'input_tokens' => 100,
            'output_tokens' => 20,
            'cached_input_tokens' => 40,
            'reasoning_tokens' => 12,
        ], $usage->jsonSerialize());
    }
}
