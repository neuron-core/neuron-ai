<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Chat\Messages;

use NeuronAI\Chat\Messages\ContentBlocks\SystemContent;
use NeuronAI\Chat\Messages\SystemMessage;
use PHPUnit\Framework\TestCase;

class MessageTest extends TestCase
{
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
