<?php

declare(strict_types=1);

namespace NeuronAI\Tests\RAG\Nodes;

use NeuronAI\Agent\AgentRunOptions;
use NeuronAI\Agent\AgentState;
use NeuronAI\Agent\Events\AgentStartEvent;
use NeuronAI\Chat\History\ChatHistory;
use NeuronAI\Chat\History\InMemoryMessageStore;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ContentBlocks\SystemContent;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\RAG\Nodes\PreProcessNode;
use NeuronAI\Tests\RAG\Stub\SuffixPreProcessor;
use NeuronAI\Tests\Support\AgentResourcesFactory;
use PHPUnit\Framework\TestCase;

class PreProcessNodeTest extends TestCase
{
    public function test_the_last_start_message_is_the_retrieval_query(): void
    {
        $question = new UserMessage('Second question');
        $event = new AgentStartEvent([new UserMessage('First question'), $question]);

        $result = (new PreProcessNode([]))($event, new AgentState(), AgentResourcesFactory::make());

        $this->assertSame($question, $result->query);
        $this->assertNull($result->getFilters());
    }

    public function test_without_start_messages_the_last_history_message_is_the_query(): void
    {
        $history = new ChatHistory(new InMemoryMessageStore(), 'thread');
        $history->addMessage(new UserMessage('Stored question'));

        $result = (new PreProcessNode([]))(new AgentStartEvent(), new AgentState(), AgentResourcesFactory::make(history: $history));

        $this->assertSame('Stored question', $result->query->getContent());
    }

    public function test_pre_processors_run_in_order_each_on_the_previous_output(): void
    {
        $first = new SuffixPreProcessor(' expanded');
        $second = new SuffixPreProcessor(' rewritten');
        $question = new UserMessage('Question');

        $result = (new PreProcessNode([$first, $second]))(new AgentStartEvent([$question]), new AgentState(), AgentResourcesFactory::make());

        $this->assertSame([$question], $first->received);
        $this->assertSame('Question expanded', $second->received[0]->getContent());
        $this->assertSame('Question expanded rewritten', $result->query->getContent());
    }

    public function test_pre_processing_rewrites_only_the_query_not_the_request_messages(): void
    {
        $question = new UserMessage('Question');
        $state = new AgentState();

        (new PreProcessNode([new SuffixPreProcessor(' rewritten')]))(new AgentStartEvent([$question]), $state, AgentResourcesFactory::make());

        $this->assertSame([$question], $state->request->messages);
        $this->assertSame('Question', $question->getContent());
    }

    public function test_the_request_starts_from_the_start_payload_and_a_copy_of_the_instructions(): void
    {
        $messages = [new UserMessage('Question')];
        $options = new AgentRunOptions(stream: true, maxRetries: 2);
        $resources = AgentResourcesFactory::make(instructions: 'Base instructions');
        $state = new AgentState();

        (new PreProcessNode([]))(new AgentStartEvent($messages, $options), $state, $resources);

        $this->assertSame($messages, $state->request->messages);
        $this->assertSame($options, $state->request->options);
        $this->assertNotSame($resources->instructions, $state->request->instructions);
        $this->assertSame('Base instructions', $state->request->instructions->getContent());

        $state->request->instructions->addContent(new SystemContent('Retrieved context'));

        $this->assertSame('Base instructions', $resources->instructions->getContent());
    }

    public function test_a_new_entry_replaces_the_previous_request(): void
    {
        $state = new AgentState();
        $node = new PreProcessNode([]);
        $node(new AgentStartEvent([new UserMessage('First')]), $state, AgentResourcesFactory::make());
        $state->request->messages[] = new AssistantMessage('Tool traffic');

        $node(new AgentStartEvent([new UserMessage('Second')]), $state, AgentResourcesFactory::make());

        $this->assertCount(1, $state->request->messages);
        $this->assertSame('Second', $state->request->messages[0]->getContent());
    }
}
