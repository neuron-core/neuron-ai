<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Providers\Mistral;

use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ContentBlocks\ReasoningContent;
use NeuronAI\Providers\Mistral\MessageMapper;
use PHPUnit\Framework\TestCase;

/**
 * Mistral ThinkChunk: {"type": "thinking", "thinking": [TextChunk, ...]}
 */
class MistralReasoningMappingTest extends TestCase
{
    public function test_reasoning_is_sent_back_as_a_list_of_thinking_chunks(): void
    {
        $mapped = (new MessageMapper())->map([new AssistantMessage([new ReasoningContent('Let me think')])]);

        $this->assertSame(
            ['type' => 'thinking', 'thinking' => [['type' => 'text', 'text' => 'Let me think']]],
            $mapped[0]['content'][0],
        );
    }
}
