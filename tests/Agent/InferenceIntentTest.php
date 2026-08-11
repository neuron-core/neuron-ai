<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Agent;

use NeuronAI\Agent\Events\AgentStartEvent;
use NeuronAI\Agent\Events\AIInferenceEvent;
use NeuronAI\Agent\Events\StructuredInferenceEvent;
use NeuronAI\Chat\Messages\SystemMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Tools\Tool;
use PHPUnit\Framework\TestCase;
use stdClass;

use function serialize;
use function unserialize;

class IntentDummyTool extends Tool
{
    protected string $name = 'intent_dummy';

    protected ?string $description = 'Dummy tool for intent tests';

    public function __invoke(): string
    {
        return 'ok';
    }
}

class InferenceIntentTest extends TestCase
{
    public function testDefaultIntent(): void
    {
        $event = new AgentStartEvent();

        $this->assertFalse($event->stream);
        $this->assertNull($event->outputClass);
        $this->assertSame(1, $event->maxTries);
    }

    public function testSetStreamRecordsTheFlagOnTheSameInstance(): void
    {
        $event = new AgentStartEvent();

        $this->assertSame($event, $event->setStream());
        $this->assertTrue($event->stream);

        $event->setStream(false);
        $this->assertFalse($event->stream);
    }

    public function testSetStructuredOutputRecordsInPlaceOnEveryClass(): void
    {
        foreach ([
            new AgentStartEvent(),
            new AIInferenceEvent('Be helpful', []),
            new StructuredInferenceEvent('Be helpful', []),
        ] as $event) {
            $this->assertSame($event, $event->setStructuredOutput(stdClass::class, 3));
            $this->assertSame(stdClass::class, $event->outputClass);
            $this->assertSame(3, $event->maxTries);
        }
    }

    public function testRoutedWithoutStructuredIntentReturnsTheSameInstance(): void
    {
        $event = new AIInferenceEvent(new SystemMessage('Be helpful'), []);
        $event->setStream();

        $this->assertSame($event, $event->routed());
    }

    public function testRoutedDerivesTheStructuredEventFromRecordedIntent(): void
    {
        $tool = IntentDummyTool::make();
        $event = new AIInferenceEvent(new SystemMessage('Be helpful'), [$tool]);
        $event->setMessages(new UserMessage('Hi'));
        $event->setStream();
        $event->setStructuredOutput(stdClass::class, 2);

        $routed = $event->routed();

        $this->assertInstanceOf(StructuredInferenceEvent::class, $routed);
        $this->assertNotSame($event, $routed);
        $this->assertSame(stdClass::class, $routed->outputClass);
        $this->assertSame(2, $routed->maxTries);

        // The derivation carries instructions, tools, messages, and stream intent.
        $this->assertSame($event->instructions, $routed->instructions);
        $this->assertSame([$tool], $routed->tools);
        $this->assertCount(1, $routed->getMessages());
        $this->assertTrue($routed->stream);
    }

    public function testRoutedIsIdempotentOnAnAlreadyStructuredEvent(): void
    {
        $event = new StructuredInferenceEvent(new SystemMessage('Be helpful'), []);
        $event->setStructuredOutput(stdClass::class, 5);

        $this->assertSame($event, $event->routed());
        $this->assertSame($event, $event->routed()->routed());
    }

    public function testMaxTriesIsNormalizedToAtLeastOne(): void
    {
        $start = (new AgentStartEvent())->setStructuredOutput(stdClass::class, 0);
        $this->assertSame(1, $start->maxTries);

        $inference = new AIInferenceEvent('Be helpful', []);
        $inference->setStructuredOutput(stdClass::class, -2);
        $this->assertSame(1, $inference->routed()->maxTries);
    }

    public function testStructuredEventSerializationKeepsIntentAndStripsTools(): void
    {
        $event = new AIInferenceEvent(new SystemMessage('Be helpful'), [IntentDummyTool::make()]);
        $event->setStream();
        $routed = $event->setStructuredOutput(stdClass::class, 4)->routed();

        /** @var StructuredInferenceEvent $restored */
        $restored = unserialize(serialize($routed));

        $this->assertInstanceOf(StructuredInferenceEvent::class, $restored);
        $this->assertSame(stdClass::class, $restored->outputClass);
        $this->assertSame(4, $restored->maxTries);
        $this->assertTrue($restored->stream);
        $this->assertSame([], $restored->tools);
    }
}
