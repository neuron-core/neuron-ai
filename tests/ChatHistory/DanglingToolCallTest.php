<?php

declare(strict_types=1);

namespace NeuronAI\Tests\ChatHistory;

use NeuronAI\Chat\History\FileChatHistory;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Tools\Tool;
use PHPUnit\Framework\TestCase;

use const DIRECTORY_SEPARATOR;

use function file_exists;
use function unlink;

class DanglingToolCallTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        $this->file = __DIR__.DIRECTORY_SEPARATOR.'neuron_dangling.chat';
    }

    protected function tearDown(): void
    {
        if (file_exists($this->file)) {
            unlink($this->file);
        }
    }

    public function test_a_trailing_tool_call_without_its_result_is_dropped_on_load(): void
    {
        $tool = Tool::make('calculator', 'A calculator');

        // A streamed turn that died between the tool call and its result: the
        // call was persisted, the result never arrived.
        $history = new FileChatHistory(__DIR__, 'dangling');
        $history->addMessage(new UserMessage('What is 2+2?'));
        $history->addMessage(new ToolCallMessage(tools: [$tool]));

        // A fresh instance loads the same file - the shape a new request sees.
        $reloaded = new FileChatHistory(__DIR__, 'dangling');
        $messages = $reloaded->getMessages();

        $this->assertCount(1, $messages);
        $this->assertInstanceOf(UserMessage::class, $messages[0]);
    }

    public function test_a_complete_tool_round_survives_the_reload(): void
    {
        $tool = Tool::make('calculator', 'A calculator')->setInputs(['operation' => 'add'])->setResult('4');

        $history = new FileChatHistory(__DIR__, 'dangling');
        $history->addMessage(new UserMessage('What is 2+2?'));
        $history->addMessage(new ToolCallMessage(tools: [$tool]));
        $history->addMessage(new ToolResultMessage(tools: [$tool]));
        $history->addMessage(new AssistantMessage('It is 4.'));

        $reloaded = new FileChatHistory(__DIR__, 'dangling');
        $messages = $reloaded->getMessages();

        $this->assertCount(4, $messages);
        $this->assertInstanceOf(ToolCallMessage::class, $messages[1]);
        $this->assertInstanceOf(ToolResultMessage::class, $messages[2]);
        $this->assertInstanceOf(AssistantMessage::class, $messages[3]);
    }
}
