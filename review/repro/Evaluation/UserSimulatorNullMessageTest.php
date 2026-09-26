<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Evaluation\Conversation;

use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Evaluation\Conversation\Trajectory;
use NeuronAI\Evaluation\Conversation\UserSimulator;
use NeuronAI\Testing\FakeAIProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class UserSimulatorNullMessageTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function continueWithoutMessage(): iterable
    {
        yield 'explicit null message' => ['{"stop":false,"message":null,"reason":null}'];
        yield 'omitted optional message' => ['{"stop":false}'];
    }

    #[DataProvider('continueWithoutMessage')]
    public function test_continuing_without_a_message_ends_the_conversation(string $response): void
    {
        $simulator = UserSimulator::make()->withGoal('Get a refund');
        $simulator->setAiProvider(new FakeAIProvider(new AssistantMessage($response)));

        $this->assertNull($simulator->nextTurn(Trajectory::fromMessages([])));
    }
}
