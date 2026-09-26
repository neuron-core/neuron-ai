<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Chat\Messages;

use NeuronAI\Chat\Messages\ContentBlocks\SystemContent;
use NeuronAI\Chat\Messages\ContentBlocks\TextContent;
use NeuronAI\Chat\Messages\SystemMessage;
use PHPUnit\Framework\TestCase;

class SystemMessageTest extends TestCase
{
    public function test_a_string_becomes_a_system_block(): void
    {
        $message = new SystemMessage('Be concise.');

        $this->assertSame('system', $message->getRole());
        $this->assertCount(1, $message->getContentBlocks());
        $this->assertSame(SystemContent::class, $message->getContentBlocks()[0]::class);
    }

    public function test_setting_a_string_replaces_the_instructions(): void
    {
        $message = new SystemMessage([new SystemContent('first'), new SystemContent('second')]);

        $message->setContents('replaced');

        $this->assertSame('replaced', $message->getContent());
        $this->assertCount(1, $message->getContentBlocks());
        $this->assertInstanceOf(SystemContent::class, $message->getContentBlocks()[0]);
    }

    public function test_the_text_view_separates_the_blocks_with_a_blank_line(): void
    {
        $message = new SystemMessage([new SystemContent('Role'), new TextContent('Rules')]);

        $this->assertSame("Role\n\nRules", $message->getContent());
    }

    public function test_empty_instructions_have_no_text(): void
    {
        $this->assertNull((new SystemMessage())->getContent());
        $this->assertNull((new SystemMessage(''))->getContent());
    }

    public function test_cache_marks_every_system_block(): void
    {
        $first = new SystemContent('first');
        $second = new SystemContent('second');
        $message = new SystemMessage([$first, $second]);

        $this->assertSame($message, $message->cache());

        $this->assertTrue($first->isCached());
        $this->assertTrue($second->isCached());
    }

    public function test_cache_leaves_non_system_blocks_untouched(): void
    {
        $text = new TextContent('plain');

        (new SystemMessage([$text]))->cache();

        $this->assertSame(['type' => $text->getType(), 'content' => 'plain', 'meta' => []], $text->toArray());
    }

    public function test_contains_searches_every_text_block(): void
    {
        $message = new SystemMessage([new SystemContent('You are a helpful assistant.'), new TextContent('Answer in French.')]);

        $this->assertTrue($message->contains('helpful'));
        $this->assertTrue($message->contains('in French'));
        $this->assertFalse($message->contains('assistant. Answer'));
        $this->assertFalse($message->contains('HELPFUL'));
    }
}
