<?php

declare(strict_types=1);

namespace NeuronAI\Tests\RAG\Nodes;

use NeuronAI\Agent\AgentState;
use NeuronAI\Agent\Events\AgentStartEvent;
use NeuronAI\Agent\Events\AIInferenceEvent;
use NeuronAI\Agent\Events\RecallMemoryEvent;
use NeuronAI\Agent\Events\StructuredInferenceEvent;
use NeuronAI\Chat\Messages\ContentBlocks\SystemContent;
use NeuronAI\Chat\Messages\SystemMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\Events\DocumentsProcessedEvent;
use NeuronAI\RAG\Nodes\InstructionsNode;
use PHPUnit\Framework\TestCase;
use stdClass;

use function str_contains;

class InstructionsNodeIntentTest extends TestCase
{
    protected function node(bool $routeThroughMemory = false): InstructionsNode
    {
        return new InstructionsNode(new SystemMessage('Base instructions'), [], $routeThroughMemory);
    }

    protected function event(AgentStartEvent $startEvent): DocumentsProcessedEvent
    {
        return new DocumentsProcessedEvent(
            new UserMessage('What is Neuron?'),
            [new Document('Neuron is a PHP agent framework.')],
            $startEvent,
        );
    }

    public function test_default_intent_produces_plain_inference_event(): void
    {
        $inference = ($this->node())($this->event(new AgentStartEvent()), new AgentState());

        $this->assertSame(AIInferenceEvent::class, $inference::class);
        $this->assertFalse($inference->stream);
    }

    public function test_stream_intent_survives_the_retrieval_boundary(): void
    {
        $startEvent = (new AgentStartEvent())->setStream();

        $inference = ($this->node())($this->event($startEvent), new AgentState());

        $this->assertSame(AIInferenceEvent::class, $inference::class);
        $this->assertTrue($inference->stream);
    }

    public function test_structured_intent_converts_to_the_routed_class(): void
    {
        $startEvent = new AgentStartEvent();
        $startEvent->setStructuredOutput(stdClass::class, 3);

        $inference = ($this->node())($this->event($startEvent), new AgentState());

        $this->assertInstanceOf(StructuredInferenceEvent::class, $inference);
        $this->assertSame(stdClass::class, $inference->outputClass);
        $this->assertSame(3, $inference->maxTries);

        // Document enrichment is preserved through the conversion.
        $matched = false;
        foreach ($inference->instructions->getContentBlocks() as $block) {
            if ($block instanceof SystemContent && str_contains((string) $block, 'Neuron is a PHP agent framework.')) {
                $matched = true;
            }
        }
        $this->assertTrue($matched, 'Enriched context block not found on the converted event.');
    }

    public function test_memory_enabled_routes_the_inference_through_recall(): void
    {
        $recall = ($this->node(true))($this->event(new AgentStartEvent()), new AgentState());

        $this->assertInstanceOf(RecallMemoryEvent::class, $recall);
        $this->assertSame(AIInferenceEvent::class, $recall->inferenceEvent::class);
    }
}
