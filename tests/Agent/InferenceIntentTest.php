<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Agent;

use NeuronAI\Tests\Agent\Stub\IntentDummyTool;
use NeuronAI\Agent\Events\AgentStartEvent;
use NeuronAI\Agent\Events\AIInferenceEvent;
use NeuronAI\Agent\Events\StructuredInferenceEvent;
use NeuronAI\Chat\Messages\SystemMessage;
use NeuronAI\Chat\Messages\UserMessage;
use PHPUnit\Framework\TestCase;
use stdClass;

use function serialize;
use function unserialize;

class InferenceIntentTest extends TestCase
{
    public function test_default_intent(): void
    {
        $event = new AgentStartEvent();

        $this->assertFalse($event->stream);
        $this->assertNull($event->outputClass);
        $this->assertSame(1, $event->maxTries);
        $this->assertTrue($event->recallMemory);
        $this->assertTrue($event->rememberMemory);
    }

    public function test_set_stream_records_the_flag_on_the_same_instance(): void
    {
        $event = new AgentStartEvent();

        $this->assertSame($event, $event->setStream());
        $this->assertTrue($event->stream);

        $event->setStream(false);
        $this->assertFalse($event->stream);
    }

    public function test_set_memory_usage_records_both_independent_choices(): void
    {
        $event = new AgentStartEvent();

        $this->assertSame($event, $event->setMemoryUsage(recall: false));
        $this->assertFalse($event->recallMemory);
        $this->assertTrue($event->rememberMemory);

        $event->setMemoryUsage(remember: false);
        $this->assertTrue($event->recallMemory);
        $this->assertFalse($event->rememberMemory);
    }

    public function test_set_structured_output_records_in_place_on_every_class(): void
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

    public function test_routed_without_structured_intent_returns_the_same_instance(): void
    {
        $event = new AIInferenceEvent(new SystemMessage('Be helpful'), []);
        $event->setStream();

        $this->assertSame($event, $event->routed());
    }

    public function test_routed_derives_the_structured_event_from_recorded_intent(): void
    {
        $tool = IntentDummyTool::make();
        $event = new AIInferenceEvent(new SystemMessage('Be helpful'), [$tool]);
        $event->setMessages(new UserMessage('Hi'));
        $event->setStream();
        $event->setMemoryUsage(recall: false, remember: false);
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
        $this->assertFalse($routed->recallMemory);
        $this->assertFalse($routed->rememberMemory);
    }

    public function test_routed_is_idempotent_on_an_already_structured_event(): void
    {
        $event = new StructuredInferenceEvent(new SystemMessage('Be helpful'), []);
        $event->setStructuredOutput(stdClass::class, 5);

        $this->assertSame($event, $event->routed());
        $this->assertSame($event, $event->routed()->routed());
    }

    public function test_max_tries_is_normalized_to_at_least_one(): void
    {
        $start = (new AgentStartEvent())->setStructuredOutput(stdClass::class, 0);
        $this->assertSame(1, $start->maxTries);

        $inference = new AIInferenceEvent('Be helpful', []);
        $inference->setStructuredOutput(stdClass::class, -2);
        $this->assertSame(1, $inference->routed()->maxTries);
    }

    public function test_structured_event_serialization_keeps_intent_and_strips_tools(): void
    {
        $event = new AIInferenceEvent(new SystemMessage('Be helpful'), [IntentDummyTool::make()]);
        $event->setStream();
        $event->setMemoryUsage(recall: false, remember: false);
        $routed = $event->setStructuredOutput(stdClass::class, 4)->routed();

        /** @var StructuredInferenceEvent $restored */
        $restored = unserialize(serialize($routed));

        $this->assertInstanceOf(StructuredInferenceEvent::class, $restored);
        $this->assertSame(stdClass::class, $restored->outputClass);
        $this->assertSame(4, $restored->maxTries);
        $this->assertTrue($restored->stream);
        $this->assertFalse($restored->recallMemory);
        $this->assertFalse($restored->rememberMemory);
        $this->assertSame([], $restored->tools);
    }
}
