<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Providers;

use NeuronAI\Providers\BasicStreamState;
use PHPUnit\Framework\TestCase;

class BasicStreamStateTest extends TestCase
{
    public function test_usage_counters_accumulate_across_events(): void
    {
        $state = (new BasicStreamState())
            ->addInputTokens(10)
            ->addInputTokens(5)
            ->addOutputTokens(3)
            ->addOutputTokens(4)
            ->addCachedInputTokens(2)
            ->addCachedInputTokens(1)
            ->addReasoningTokens(6)
            ->addReasoningTokens(0);

        $usage = $state->getUsage();
        $this->assertSame(15, $usage->inputTokens);
        $this->assertSame(7, $usage->outputTokens);
        $this->assertSame(3, $usage->cachedInputTokens);
        $this->assertSame(6, $usage->reasoningTokens);
    }

    public function test_message_id_is_stable_within_a_stream_and_unique_across_streams(): void
    {
        $state = new BasicStreamState();
        $other = new BasicStreamState();

        $this->assertStringStartsWith('msg_', $state->messageId());
        $this->assertSame($state->messageId(), $state->messageId());
        $this->assertNotSame($state->messageId(), $other->messageId());
    }

    public function test_accumulated_metadata_concatenates_fragments_in_order(): void
    {
        $state = (new BasicStreamState())
            ->accumulateMetadata('reasoning_content', 'Let ')
            ->accumulateMetadata('reasoning_content', '0')
            ->accumulateMetadata('reasoning_content', '')
            ->accumulateMetadata('reasoning_content', ' think');

        $this->assertSame('Let 0 think', $state->getMetadata('reasoning_content'));
        $this->assertTrue($state->hasMetadata('reasoning_content'));
    }

    public function test_added_metadata_overwrites_and_missing_keys_read_as_null(): void
    {
        $state = (new BasicStreamState())
            ->addMetadata('positions', [1])
            ->addMetadata('positions', [1, 3]);

        $this->assertSame([1, 3], $state->getMetadata('positions'));
        $this->assertSame(['positions' => [1, 3]], $state->getMetadata());
        $this->assertNull($state->getMetadata('missing'));
        $this->assertFalse($state->hasMetadata('missing'));
    }

    public function test_starts_without_tool_calls_or_content(): void
    {
        $state = new BasicStreamState();

        $this->assertFalse($state->hasToolCalls());
        $this->assertSame([], $state->getToolCalls());
        $this->assertNull($state->getToolCall(0));
        $this->assertNull($state->getToolCall('fc_unknown'));
        $this->assertSame([], $state->getContentBlocks());
    }
}
