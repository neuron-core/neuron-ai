<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Chat\Messages;

use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ContentBlocks\SystemContent;
use NeuronAI\Chat\Messages\SystemMessage;
use NeuronAI\Chat\Messages\UserMessage;
use PHPUnit\Framework\TestCase;

class MessageTest extends TestCase
{
    public function test_each_message_gets_its_own_identity(): void
    {
        $first = new UserMessage('Hello');
        $second = new UserMessage('Hello');

        $this->assertStringStartsWith('msg_', $first->getId());
        $this->assertNotSame($first->getId(), $second->getId());
    }

    public function test_replacing_the_metadata_keeps_the_identity(): void
    {
        $message = new AssistantMessage('Hi');
        $id = $message->getId();

        $message->setMetadata(['provider_state' => 'opaque']);

        $this->assertSame($id, $message->getId());
        $this->assertSame('opaque', $message->getMetadata('provider_state'));
    }

    public function test_a_streamed_message_takes_the_id_its_chunks_carried(): void
    {
        $message = new AssistantMessage('Hi');

        $message->setId('msg_streamed')->setMetadata(['provider_state' => 'opaque']);

        $this->assertSame('msg_streamed', $message->getId());
    }

    public function test_clone_owns_its_content_blocks(): void
    {
        $block = new SystemContent('Base instructions');
        $original = new SystemMessage($block);

        $copy = clone $original;
        [$copied] = $copy->getTextBlocks();
        $copied->content = 'Edited on the copy';
        $copy->cache();
        $copy->addContent(new SystemContent('Added on the copy'));

        $this->assertNotSame($block, $copied);
        $this->assertSame('Edited on the copy', $copied->content);
        $this->assertSame('Base instructions', $block->content);
        $this->assertFalse($block->isCached());
        $this->assertCount(1, $original->getContentBlocks());
    }
}
