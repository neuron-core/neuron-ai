<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Chat\History;

use NeuronAI\Chat\History\TokenCounter;
use NeuronAI\Chat\Messages\ContentBlocks\TextContent;
use NeuronAI\Chat\Messages\SystemMessage;
use PHPUnit\Framework\TestCase;

use function str_repeat;

class TokenCounterConfigurationTest extends TestCase
{
    public function test_system_text_is_counted_like_text_of_the_same_length(): void
    {
        $text = str_repeat('a', 4000);
        $counter = new TokenCounter();

        $systemTokens = $counter->count(new SystemMessage($text));
        $textTokens = $counter->count(new SystemMessage([new TextContent($text)]));

        // The JSON payloads differ only by the block type name ("system" vs "text")
        $this->assertEqualsWithDelta($textTokens, $systemTokens, 2);
    }
}
