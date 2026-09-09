<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Chat\History;

use NeuronAI\Chat\History\FileChatHistory;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Usage;
use NeuronAI\Chat\Messages\UserMessage;
use PHPUnit\Framework\TestCase;

use function array_filter;
use function count;
use function file_get_contents;
use function json_decode;
use function rmdir;
use function sys_get_temp_dir;
use function uniqid;

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

    public function test_trimmed_messages_are_archived_in_the_file(): void
    {
        $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'neuron_archive_' . uniqid();
        $file = $directory . DIRECTORY_SEPARATOR . 'neuron_archive.chat';

        $history = new FileChatHistory($directory, 'archive', contextWindow: 100);

        for ($i = 1; $i <= 20; $i++) {
            $history->addMessage($i % 2 !== 0
                ? new UserMessage("User message $i with some text")
                : (new AssistantMessage("Assistant message $i with some text"))->setUsage(new Usage(100 * $i, 150)));
        }

        $active = $history->getMessages();
        $this->assertLessThan(20, count($active));

        // Every message is still in the file: the trimmed ones marked as archived, oldest first.
        $entries = json_decode(file_get_contents($file), true);
        $this->assertCount(20, $entries);
        $this->assertCount(20 - count($active), $this->archivedEntries($entries));
        $this->assertSame('User message 1 with some text', $entries[0]['content'][0]['content']);

        // Only the unarchived entries are loaded back.
        $reloaded = new FileChatHistory($directory, 'archive');
        $this->assertCount(count($active), $reloaded->getMessages());
        $this->assertSame($active[0]->getContent(), $reloaded->getMessages()[0]->getContent());

        // The archived entries survive the next write.
        $reloaded->addMessage(new UserMessage('One more message'));
        $entries = json_decode(file_get_contents($file), true);
        $this->assertCount(21, $entries);
        $this->assertCount(21 - count($reloaded->getMessages()), $this->archivedEntries($entries));

        $reloaded->flushAll();
        $this->assertFileDoesNotExist($file);
        rmdir($directory);
    }

    /**
     * @param array<int, array<string, mixed>> $entries
     * @return array<int, array<string, mixed>>
     */
    protected function archivedEntries(array $entries): array
    {
        return array_filter($entries, fn (array $entry): bool => isset($entry['archived_at']));
    }
}
