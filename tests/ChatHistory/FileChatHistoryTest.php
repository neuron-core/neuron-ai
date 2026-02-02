<?php

declare(strict_types=1);

namespace NeuronAI\Tests\ChatHistory;

use NeuronAI\Chat\History\FileChatHistory;
use NeuronAI\Chat\Messages\Stream\AssistantMessage;
use NeuronAI\Chat\Messages\UserMessage;
use PHPUnit\Framework\TestCase;

use function file_get_contents;
use function json_decode;

use const DIRECTORY_SEPARATOR;

class FileChatHistoryTest extends TestCase
{
    public function test_file_chat_history(): void
    {
        $history = new FileChatHistory(__DIR__, 'test');
        $this->assertFileDoesNotExist(__DIR__.DIRECTORY_SEPARATOR.'neuron_test.chat');

        $history->addMessage(new UserMessage('Hello!'));
        $this->assertFileExists(__DIR__.DIRECTORY_SEPARATOR.'neuron_test.chat');
        $this->assertCount(1, $history->getMessages());

        $history->addMessage(new AssistantMessage('Hello there!'));
        $this->assertCount(2, $history->getMessages());

        $messages = json_decode(file_get_contents(__DIR__.DIRECTORY_SEPARATOR.'neuron_test.chat'), true);
        $this->assertCount(2, $messages);

        $history->flushAll();
        $this->assertFileDoesNotExist(__DIR__.DIRECTORY_SEPARATOR.'neuron_test.chat');
        $this->assertCount(0, $history->getMessages());
    }
}
