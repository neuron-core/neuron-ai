<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Chat\History;

use NeuronAI\Chat\History\ChatHistory;
use NeuronAI\Chat\History\FileMessageStore;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\Usage;
use NeuronAI\Chat\Messages\UserMessage;
use PHPUnit\Framework\TestCase;

use function array_filter;
use function array_keys;
use function array_map;
use function array_slice;
use function file_get_contents;
use function glob;
use function is_dir;
use function json_decode;
use function rmdir;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

use const DIRECTORY_SEPARATOR;

class FileChatHistoryTest extends TestCase
{
    protected string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'neuron_file_history_' . uniqid();
    }

    protected function tearDown(): void
    {
        if (is_dir($this->directory)) {
            foreach (glob($this->directory . '/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($this->directory);
        }
    }

    public function test_file_chat_history(): void
    {
        $file = $this->directory . DIRECTORY_SEPARATOR . 'neuron_test.chat';

        $history = new ChatHistory(new FileMessageStore($this->directory), 'test');
        $this->assertFileDoesNotExist($file);

        $history->addMessage(new UserMessage('Hello!'));
        $this->assertFileExists($file);
        $this->assertCount(1, $history->getMessages());

        $history->addMessage(new AssistantMessage('Hello there!'));
        $this->assertCount(2, $history->getMessages());

        $messages = json_decode((string) file_get_contents($file), true);
        $this->assertCount(2, $messages);
        $this->assertSame('Hello!', $messages[0]['content'][0]['content']);
        $this->assertSame('Hello there!', $messages[1]['content'][0]['content']);

        $history->flushAll();
        $this->assertFileDoesNotExist($file);
        $this->assertCount(0, $history->getMessages());
    }

    public function test_trimmed_messages_are_archived_in_the_file(): void
    {
        $file = $this->directory . DIRECTORY_SEPARATOR . 'neuron_archive.chat';
        $history = new ChatHistory(new FileMessageStore($this->directory), 'archive', 100);

        $messages = [];
        for ($i = 1; $i <= 20; $i++) {
            $messages[] = $i % 2 !== 0
                ? new UserMessage("User message $i with some text")
                : (new AssistantMessage("Assistant message $i with some text"))->setUsage(new Usage(100 * $i, 150));
            $history->addMessage($messages[$i - 1]);
        }

        // Every turn reports more than the 100-token window: only the last one is kept.
        $this->assertSame($this->ids(array_slice($messages, 18)), $this->ids($history->getMessages()));

        // Every message is still in the file: the trimmed ones marked as archived, oldest first.
        $entries = json_decode((string) file_get_contents($file), true);
        $this->assertSame($this->ids($messages), array_map(fn (array $entry): string => $entry['__id'], $entries));
        $this->assertSame(array_keys(array_slice($entries, 0, 18)), array_keys($this->archivedEntries($entries)));

        // Only the unarchived entries are loaded back.
        $reloaded = new ChatHistory(new FileMessageStore($this->directory), 'archive', 100);
        $this->assertSame($this->ids(array_slice($messages, 18)), $this->ids($reloaded->getMessages()));

        // The archived entries survive the next write, which archives the previous turn too.
        $next = new UserMessage('One more message');
        $reloaded->addMessage($next);
        $this->assertSame([$next->getId()], $this->ids($reloaded->getMessages()));
        $entries = json_decode((string) file_get_contents($file), true);
        $this->assertCount(21, $entries);
        $this->assertCount(20, $this->archivedEntries($entries));

        $reloaded->flushAll();
        $this->assertFileDoesNotExist($file);
    }

    /**
     * @param array<int, array<string, mixed>> $entries
     * @return array<int, array<string, mixed>>
     */
    protected function archivedEntries(array $entries): array
    {
        return array_filter($entries, fn (array $entry): bool => isset($entry['archived_at']));
    }

    /**
     * @param Message[] $messages
     * @return string[]
     */
    protected function ids(array $messages): array
    {
        return array_map(fn (Message $message): string => $message->getId(), $messages);
    }
}
