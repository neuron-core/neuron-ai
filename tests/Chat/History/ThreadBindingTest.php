<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Chat\History;

use NeuronAI\Chat\History\FileChatHistory;
use NeuronAI\Chat\History\InMemoryChatHistory;
use NeuronAI\Chat\History\SQLChatHistory;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Exceptions\ChatHistoryException;
use PDO;
use PHPUnit\Framework\TestCase;

use function file_put_contents;
use function is_dir;
use function json_encode;
use function mkdir;
use function rmdir;
use function scandir;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

/**
 * The binding model: histories are thread-scoped by nature but constructible
 * without their thread — loading is lazy, the thread is bound (once) before
 * first use, and using a durable history that was never bound fails loudly.
 */
class ThreadBindingTest extends TestCase
{
    protected string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/neuron_binding_' . uniqid();
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->directory)) {
            return;
        }
        foreach (scandir($this->directory) as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                unlink($this->directory . '/' . $entry);
            }
        }
        rmdir($this->directory);
    }

    protected function sqlite(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE chat_messages (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            thread_id TEXT, role TEXT, content TEXT, meta TEXT
        )');

        return $pdo;
    }

    public function test_loading_is_deferred_to_first_use(): void
    {
        // Construct first, create the stored conversation AFTER: an eager
        // constructor load would miss it; the lazy read finds it.
        $history = new FileChatHistory($this->directory, 'lazy-thread');

        file_put_contents(
            $this->directory . '/neuron_lazy-thread.chat',
            json_encode([['role' => 'user', 'content' => 'written after construction']]),
        );

        $messages = $history->getMessages();

        $this->assertCount(1, $messages);
        $this->assertSame('written after construction', $messages[0]->getContent());
    }

    public function test_bind_after_construction_loads_the_bound_thread(): void
    {
        $pdo = $this->sqlite();
        $pdo->exec("INSERT INTO chat_messages (thread_id, role, content) VALUES
            ('thread-a', 'user', '\"from thread a\"'),
            ('thread-b', 'user', '\"from thread b\"')");

        $history = new SQLChatHistory($pdo);        // identity-free construction
        $history->setThreadId('thread-a');           // the framework's job, done by hand here

        $messages = $history->getMessages();

        $this->assertCount(1, $messages);
        $this->assertSame('from thread a', $messages[0]->getContent());
    }

    public function test_rebinding_to_a_different_thread_throws(): void
    {
        $history = new SQLChatHistory($this->sqlite());
        $history->setThreadId('thread-a');
        $history->setThreadId('thread-a');           // same id: no-op

        $this->expectException(ChatHistoryException::class);
        $this->expectExceptionMessage("cannot be re-pointed to 'thread-b'");

        $history->setThreadId('thread-b');
    }

    public function test_using_an_unbound_durable_history_fails_loud(): void
    {
        $history = new SQLChatHistory($this->sqlite());

        $this->expectException(ChatHistoryException::class);
        $this->expectExceptionMessage('thread-scoped and no thread identity');

        $history->addMessage(new UserMessage('hello'));
    }

    public function test_unbound_file_history_fails_loud_on_read(): void
    {
        $history = new FileChatHistory($this->directory);

        $this->expectException(ChatHistoryException::class);
        $this->expectExceptionMessage('thread-scoped and no thread identity');

        $history->getMessages();
    }

    public function test_in_memory_is_always_pre_bound(): void
    {
        $this->assertNotNull((new InMemoryChatHistory())->getThreadId());
        $this->assertSame('given', (new InMemoryChatHistory('given'))->getThreadId());
    }
}
