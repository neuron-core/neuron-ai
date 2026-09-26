<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Chat\Messages;

use NeuronAI\Chat\Messages\ContentBlocks\TextContent;
use NeuronAI\Chat\Messages\SystemMessage;
use NeuronAI\Chat\Messages\UserMessage;
use PHPUnit\Framework\TestCase;

class SetContentsReplaceTest extends TestCase
{
    public function test_setting_a_list_of_blocks_replaces_the_previous_content(): void
    {
        $message = new UserMessage('first');

        $message->setContents([new TextContent('second')]);

        $this->assertSame('second', $message->getContent());
        $this->assertCount(1, $message->getContentBlocks());
    }

    public function test_setting_a_list_of_blocks_on_a_system_message_replaces_the_previous_content(): void
    {
        $message = new SystemMessage('first');

        $message->setContents([new TextContent('second')]);

        $this->assertSame('second', $message->getContent());
        $this->assertCount(1, $message->getContentBlocks());
    }

    public function test_setting_an_empty_list_clears_the_content(): void
    {
        $message = new UserMessage('first');

        $message->setContents([]);

        $this->assertNull($message->getContent());
        $this->assertSame([], $message->getContentBlocks());
    }
}
