<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Chat\Messages;

use NeuronAI\Chat\Messages\ContentBlocks\SystemContent;
use NeuronAI\Chat\Messages\MessageDeserializer;
use NeuronAI\Chat\Messages\SystemMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Evaluation\Conversation\Trajectory;
use PHPUnit\Framework\TestCase;

use function json_decode;
use function json_encode;
use function serialize;
use function unserialize;

class SystemMessageRoundTripTest extends TestCase
{
    public function test_a_system_message_comes_back_as_a_system_message(): void
    {
        $original = new SystemMessage([new SystemContent('Be concise.'), new SystemContent('Answer in English.')]);

        $restored = (new MessageDeserializer())->deserialize(json_decode(json_encode($original), true));

        $this->assertInstanceOf(SystemMessage::class, $restored);
        $this->assertSame($original->getId(), $restored->getId());
        $this->assertSame("Be concise.\n\nAnswer in English.", $restored->getContent());
    }

    public function test_instructions_stay_out_of_the_transcript_after_a_trajectory_crosses_a_serialization_boundary(): void
    {
        $trajectory = Trajectory::fromMessages([
            new SystemMessage('SECRET SYSTEM PROMPT'),
            new UserMessage('Hi'),
        ]);

        $restored = unserialize(serialize($trajectory));

        $this->assertSame($trajectory->toTranscript(), $restored->toTranscript());
        $this->assertSame('User: Hi', $restored->toTranscript());
    }
}
