<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Agent;

use NeuronAI\Agent\ContentHelper;
use PHPUnit\Framework\TestCase;

use function str_repeat;

class ContentHelperLargeInputTest extends TestCase
{
    public function test_a_large_unterminated_block_does_not_crash(): void
    {
        $text = str_repeat('<think>', 200000) . 'answer';

        $this->assertSame($text, ContentHelper::removeDelimitedContent($text, '<think>', '</think>'));
    }

    public function test_a_large_terminated_block_is_removed(): void
    {
        $text = 'before<think>' . str_repeat('reasoning ', 500000) . '</think>after';

        $this->assertSame('beforeafter', ContentHelper::removeDelimitedContent($text, '<think>', '</think>'));
    }
}
