<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Chat\History;

use NeuronAI\Chat\History\HistoryTrimmer;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Usage;
use NeuronAI\Chat\Messages\UserMessage;
use PHPUnit\Framework\TestCase;

use const PHP_INT_MAX;

class TrimmerCheckpointCacheTest extends TestCase
{
    public function test_usage_set_on_the_last_message_after_a_trim_is_measured(): void
    {
        $trimmer = new HistoryTrimmer();
        $messages = [new UserMessage('Hello'), new AssistantMessage('Hello')];
        $trimmer->trim($messages, PHP_INT_MAX);
        $estimated = $trimmer->getTotalTokens();

        $messages[1]->setUsage(new Usage(5000, 10));
        $trimmer->trim($messages, PHP_INT_MAX);

        $this->assertNotSame(5010, $estimated);
        $this->assertSame(5010, $trimmer->getTotalTokens());
    }

    public function test_a_conversation_of_the_same_length_is_not_measured_with_checkpoints_of_a_freed_one(): void
    {
        $trimmer = new HistoryTrimmer();
        $reference = new HistoryTrimmer();
        $user = new UserMessage('Hello');

        $trimmer->trim([$user, (new AssistantMessage('Hello'))->setUsage(new Usage(1000, 10))], PHP_INT_MAX);
        $this->assertSame(1010, $trimmer->getTotalTokens());

        $unmeasured = [$user, new AssistantMessage('Hello')];
        $trimmer->trim($unmeasured, PHP_INT_MAX);
        $reference->trim($unmeasured, PHP_INT_MAX);

        $this->assertSame($reference->getTotalTokens(), $trimmer->getTotalTokens());
    }
}
