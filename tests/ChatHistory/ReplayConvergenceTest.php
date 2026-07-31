<?php

declare(strict_types=1);

namespace NeuronAI\Tests\ChatHistory;

use NeuronAI\Chat\History\InMemoryChatHistory;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Tools\ToolDefinition;
use PHPUnit\Framework\TestCase;

/**
 * Nodes write to the live chat history and re-run against it on crash-replay,
 * so a re-added message must converge onto the tail instead of duplicating
 * (extends the ADR 0003 replace-last rule beyond ToolCallMessage).
 */
class ReplayConvergenceTest extends TestCase
{
    public function test_re_adding_the_same_message_instance_replaces_the_tail(): void
    {
        $history = new InMemoryChatHistory();
        $message = new UserMessage('Search for PHP frameworks');

        $history->addMessage($message);
        $history->addMessage($message);

        $this->assertCount(1, $history->getMessages());
    }

    public function test_rebuilt_message_with_same_content_replaces_the_tail(): void
    {
        // Cross-process replay: the same logical turn is re-built as a fresh
        // object with a fresh id but identical content.
        $history = new InMemoryChatHistory();

        $history->addMessage(new UserMessage('Search for PHP frameworks'));
        $history->addMessage(new UserMessage('Search for PHP frameworks'));

        $this->assertCount(1, $history->getMessages());
    }

    public function test_different_content_still_appends(): void
    {
        $history = new InMemoryChatHistory();

        $history->addMessage(new UserMessage('First question'));
        $history->addMessage(new AssistantMessage('First answer'));
        $history->addMessage(new UserMessage('Second question'));

        $this->assertCount(3, $history->getMessages());
    }

    public function test_same_content_different_roles_still_appends(): void
    {
        $history = new InMemoryChatHistory();

        $history->addMessage(new UserMessage('Ok'));
        $history->addMessage(new AssistantMessage('Ok'));

        $this->assertCount(2, $history->getMessages());
    }

    public function test_tool_result_re_add_converges_by_call_ids(): void
    {
        $history = new InMemoryChatHistory();
        $history->addMessage(new UserMessage('Run the tool'));
        $history->addMessage(new ToolCallMessage(null, [
            ToolDefinition::make('search', 'Search')->setCallId('call_1'),
        ]));

        $result1 = new ToolResultMessage([
            ToolDefinition::make('search', 'Search')->setCallId('call_1')->setResult('r1'),
        ]);
        $result2 = new ToolResultMessage([
            ToolDefinition::make('search', 'Search')->setCallId('call_1')->setResult('r1'),
        ]);

        $history->addMessage($result1);
        $history->addMessage($result2);

        $this->assertCount(3, $history->getMessages());
    }
}
