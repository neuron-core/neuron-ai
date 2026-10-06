<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Chat\Messages;

use NeuronAI\Chat\Messages\ContentBlocks\SystemContent;
use NeuronAI\Chat\Messages\ContentBlocks\TextContent;
use NeuronAI\Chat\Messages\SystemMessage;
use PHPUnit\Framework\TestCase;

class SystemMessageTest extends TestCase
{
    public function test_a_string_becomes_a_text_block(): void
    {
        $message = new SystemMessage('Be concise.');

        $this->assertSame('system', $message->getRole());
        $this->assertCount(1, $message->getContentBlocks());
        $this->assertSame(TextContent::class, $message->getContentBlocks()[0]::class);
    }

    public function test_setting_a_string_replaces_the_instructions(): void
    {
        $message = new SystemMessage([new TextContent('first'), new TextContent('second')]);

        $message->setContents('replaced');

        $this->assertSame('replaced', $message->getContent());
        $this->assertCount(1, $message->getContentBlocks());
        $this->assertInstanceOf(TextContent::class, $message->getContentBlocks()[0]);
    }

    public function test_setting_a_list_of_blocks_replaces_the_instructions(): void
    {
        $message = new SystemMessage([new TextContent('first'), new TextContent('second')]);

        $message->setContents([new TextContent('replaced')]);

        $this->assertSame('replaced', $message->getContent());
        $this->assertCount(1, $message->getContentBlocks());
    }

    public function test_the_text_view_separates_the_blocks_with_a_blank_line(): void
    {
        $message = new SystemMessage([new TextContent('Role'), new TextContent('Rules')]);

        $this->assertSame("Role\n\nRules", $message->getContent());
    }

    public function test_empty_instructions_have_no_text(): void
    {
        $this->assertNull((new SystemMessage())->getContent());
        $this->assertNull((new SystemMessage(''))->getContent());
    }

    public function test_cache_marks_the_last_block_only(): void
    {
        $first = new TextContent('first');
        $second = new TextContent('second');
        $message = new SystemMessage([$first, $second]);

        $this->assertSame($message, $message->cache());

        // One breakpoint after the last block covers the whole message
        $this->assertFalse($first->isCached());
        $this->assertTrue($second->isCached());
    }

    public function test_a_deprecated_system_block_is_still_a_text_block_of_the_message(): void
    {
        $block = new SystemContent('instructions');
        $message = new SystemMessage([new TextContent('first'), $block]);

        $message->cache();

        $this->assertSame("first\n\ninstructions", $message->getContent());
        $this->assertTrue($block->isCached());
    }

    public function test_caching_an_empty_message_marks_nothing(): void
    {
        $message = new SystemMessage();

        $this->assertSame($message, $message->cache());
        $this->assertSame([], $message->getContentBlocks());
        $this->assertFalse($message->isCached());
    }

    public function test_a_message_is_cached_while_its_last_block_carries_the_breakpoint(): void
    {
        $this->assertFalse((new SystemMessage('instructions'))->isCached());
        $this->assertTrue((new SystemMessage('instructions'))->cache()->isCached());
        $this->assertTrue((new SystemMessage([new TextContent('first'), (new TextContent('second'))->cache()]))->isCached());
        $this->assertFalse((new SystemMessage([(new TextContent('first'))->cache(), new TextContent('second')]))->isCached());
    }

    public function test_a_block_added_to_a_cached_message_leaves_it_uncached_until_cached_again(): void
    {
        $message = (new SystemMessage('instructions'))->cache();

        $message->addContent($added = new TextContent('added'));
        $this->assertFalse($message->isCached());

        $message->cache();
        $this->assertTrue($message->isCached());
        $this->assertTrue($added->isCached());
    }

    public function test_contains_searches_every_text_block(): void
    {
        $message = new SystemMessage([new TextContent('You are a helpful assistant.'), new TextContent('Answer in French.')]);

        $this->assertTrue($message->contains('helpful'));
        $this->assertTrue($message->contains('in French'));
        $this->assertFalse($message->contains('assistant. Answer'));
        $this->assertFalse($message->contains('HELPFUL'));
    }
}
