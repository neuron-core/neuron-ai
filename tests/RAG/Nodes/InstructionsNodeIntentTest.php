<?php

declare(strict_types=1);

namespace NeuronAI\Tests\RAG\Nodes;

use NeuronAI\Agent\AgentState;
use NeuronAI\Agent\AgentRunOptions;
use NeuronAI\Agent\Events\AgentStartEvent;
use NeuronAI\Agent\Events\AIInferenceEvent;
use NeuronAI\Agent\Events\RecallMemoryEvent;
use NeuronAI\Agent\Events\StructuredInferenceEvent;
use NeuronAI\Chat\History\InMemoryChatHistory;
use NeuronAI\Chat\Messages\ContentBlocks\SystemContent;
use NeuronAI\Chat\Messages\SystemMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\Events\DocumentsProcessedEvent;
use NeuronAI\RAG\Nodes\InstructionsNode;
use NeuronAI\RAG\Nodes\PreProcessNode;
use PHPUnit\Framework\TestCase;
use stdClass;

class InstructionsNodeIntentTest extends TestCase
{
    protected function enter(AgentRunOptions $options): AgentState
    {
        $state = new AgentState();
        $node = new PreProcessNode(new InMemoryChatHistory(), [], new SystemMessage('Base instructions'), []);
        $node(new AgentStartEvent([new UserMessage('What is Neuron?')], $options), $state);

        return $state;
    }

    protected function event(): DocumentsProcessedEvent
    {
        return new DocumentsProcessedEvent(
            new UserMessage('What is Neuron?'),
            [new Document('Neuron is a PHP agent framework.')],
        );
    }

    public function test_new_rag_entry_resets_tool_counters(): void
    {
        $state = $this->enter(new AgentRunOptions());
        $state->incrementToolRun('lookup');
        $node = new PreProcessNode(new InMemoryChatHistory(), [], new SystemMessage('Test'), []);
        $node(new AgentStartEvent([new UserMessage('New question')]), $state);

        $this->assertSame(0, $state->getToolRuns('lookup'));
    }

    public function test_default_intent_produces_plain_inference_event(): void
    {
        $state = $this->enter(new AgentRunOptions());
        $inference = (new InstructionsNode())($this->event(), $state);

        $this->assertSame(AIInferenceEvent::class, $inference::class);
        $this->assertFalse($state->request->options->stream);
    }

    public function test_stream_intent_survives_the_retrieval_boundary(): void
    {
        $state = $this->enter(new AgentRunOptions(stream: true));
        $inference = (new InstructionsNode())($this->event(), $state);

        $this->assertSame(AIInferenceEvent::class, $inference::class);
        $this->assertTrue($state->request->options->stream);
    }

    public function test_enrichment_preserves_the_request_and_earlier_middleware_changes(): void
    {
        $options = new AgentRunOptions(outputClass: stdClass::class, maxRetries: 3);
        $state = $this->enter($options);
        $request = $state->request;
        $request->instructions->addContent(new SystemContent('Middleware context'));
        $inference = (new InstructionsNode())($this->event(), $state);

        $this->assertInstanceOf(StructuredInferenceEvent::class, $inference);
        $this->assertSame($request, $state->request);
        $this->assertSame($options, $state->request->options);
        $this->assertSame(3, $state->request->options->maxRetries);
        $this->assertTrue($state->request->instructions->contains('Middleware context'));
        $this->assertTrue($state->request->instructions->contains('Neuron is a PHP agent framework.'));
    }

    public function test_memory_enabled_routes_the_inference_through_recall(): void
    {
        $state = $this->enter(new AgentRunOptions());
        $request = $state->request;
        $recall = (new InstructionsNode(true))($this->event(), $state);

        $this->assertInstanceOf(RecallMemoryEvent::class, $recall);
        $this->assertSame($request, $state->request);
    }

    public function test_memory_usage_survives_retrieval_and_controls_recall(): void
    {
        $state = $this->enter(new AgentRunOptions(recallMemory: false, rememberMemory: false));
        $inference = (new InstructionsNode(true))($this->event(), $state);

        $this->assertSame(AIInferenceEvent::class, $inference::class);
        $this->assertFalse($state->request->options->recallMemory);
        $this->assertFalse($state->request->options->rememberMemory);
    }
}
