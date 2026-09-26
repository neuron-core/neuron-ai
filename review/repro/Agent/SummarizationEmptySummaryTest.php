<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Agent\Middleware;

use NeuronAI\Agent\AgentState;
use NeuronAI\Agent\Events\AIInferenceEvent;
use NeuronAI\Agent\Middleware\Summarization;
use NeuronAI\Agent\Nodes\ChatNode;
use NeuronAI\Chat\History\ChatHistory;
use NeuronAI\Chat\History\InMemoryMessageStore;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Tests\Support\AgentResourcesFactory;
use PHPUnit\Framework\TestCase;

class SummarizationEmptySummaryTest extends TestCase
{
    public function test_a_summary_without_text_degrades_to_the_placeholder_instead_of_failing_the_run(): void
    {
        $history = new ChatHistory(new InMemoryMessageStore(), 'thread');
        foreach ([new UserMessage('Q1'), new AssistantMessage('A1'), new UserMessage('Q2'), new AssistantMessage('A2')] as $message) {
            $history->addMessage($message);
        }
        $provider = new FakeAIProvider(new AssistantMessage(null));

        (new Summarization($provider, maxTokens: 1, messagesToKeep: 1))
            ->before(new ChatNode(), new AIInferenceEvent(), new AgentState(), AgentResourcesFactory::make([], $history, $provider));

        $messages = $history->getMessages();
        $this->assertCount(2, $messages);
        $this->assertSame(
            "## Previous conversation summary:\n\nPrevious conversation contained 3 messages covering various topics.",
            $messages[0]->getContent()
        );
        $this->assertSame('A2', $messages[1]->getContent());
    }
}
