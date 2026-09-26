<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Chat\History;

use NeuronAI\Chat\History\HistoryTrimmer;
use NeuronAI\Chat\History\TokenCounter;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\UserMessage;
use PHPUnit\Framework\TestCase;

use function array_map;
use function array_sum;

class EstimatedTrimOverWindowTest extends TestCase
{
    public function test_a_trim_by_estimation_fits_the_window_when_a_valid_cut_exists(): void
    {
        // Without usage, each user 'Hello' is estimated at 12 tokens and each assistant 'Hello' at 13: 75 in total.
        $messages = [
            new UserMessage('Hello'), new AssistantMessage('Hello'),
            new UserMessage('Hello'), new AssistantMessage('Hello'),
            new UserMessage('Hello'), new AssistantMessage('Hello'),
        ];

        $counter = new TokenCounter();
        $this->assertSame([12, 13, 12, 13, 12, 13], array_map(fn (Message $message): int => $counter->count($message), $messages));

        // The last three messages (38 tokens) fit, but a history must start with a user message:
        // keeping the last turn (25 tokens) is the only valid cut inside the window.
        $kept = (new HistoryTrimmer())->trim($messages, 40);

        $this->assertLessThanOrEqual(40, array_sum(array_map(fn (Message $message): int => $counter->count($message), $kept)));
        $this->assertSame([$messages[4], $messages[5]], $kept);
    }
}
