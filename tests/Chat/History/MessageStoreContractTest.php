<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Chat\History;

use Illuminate\Database\Capsule\Manager as Capsule;
use NeuronAI\Chat\History\EloquentMessageStore;
use NeuronAI\Chat\History\FileMessageStore;
use NeuronAI\Chat\History\InMemoryMessageStore;
use NeuronAI\Chat\History\MessageStoreInterface;
use NeuronAI\Chat\History\SQLMessageStore;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Tests\Chat\History\Stub\ChatMessage;
use NeuronAI\Tests\Chat\History\Stub\SqliteMessageStore;
use NeuronAI\Tools\ToolCall;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function array_map;
use function array_slice;
use function glob;
use function is_dir;
use function rmdir;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

use const GLOB_BRACE;

class MessageStoreContractTest extends TestCase
{
    protected string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/neuron_messages_' . uniqid();
    }

    protected function tearDown(): void
    {
        if (is_dir($this->directory)) {
            foreach (glob($this->directory . '/{,.}*.chat*', GLOB_BRACE) ?: [] as $file) {
                unlink($file);
            }
            rmdir($this->directory);
        }
    }

    /**
     * @return array<string, array{0: callable(string): MessageStoreInterface}>
     */
    public static function stores(): array
    {
        return [
            'in-memory' => [fn (string $directory): MessageStoreInterface => new InMemoryMessageStore()],
            'file' => [fn (string $directory): MessageStoreInterface => new FileMessageStore($directory)],
            'sql' => [fn (string $directory): MessageStoreInterface => new SqliteMessageStore()],
            'eloquent' => [fn (string $directory): MessageStoreInterface => self::eloquentStore()],
            'mysql' => [fn (string $directory): MessageStoreInterface => self::mysqlStore()],
        ];
    }

    protected static function eloquentStore(): EloquentMessageStore
    {
        $capsule = new Capsule();
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $capsule->setAsGlobal();
        $capsule->bootEloquent();
        $capsule->getConnection()->statement(SqliteMessageStore::SCHEMA);

        return new EloquentMessageStore(ChatMessage::class);
    }

    protected static function mysqlStore(): SQLMessageStore
    {
        try {
            $pdo = new PDO('mysql:host=127.0.0.1;dbname=neuron-ai', 'root', '');
        } catch (PDOException) {
            self::markTestSkipped('MySQL not available on port 3306.');
        }

        $pdo->exec('DROP TABLE IF EXISTS chat_messages');
        $pdo->exec('CREATE TABLE chat_messages (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            thread_id VARCHAR(255) NOT NULL,
            message_id VARCHAR(64) NOT NULL,
            role VARCHAR(32) NOT NULL,
            content LONGTEXT NULL,
            meta LONGTEXT NULL,
            archived_at DATETIME NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_thread_id (thread_id),
            UNIQUE INDEX idx_thread_message (thread_id, message_id)
        )');

        return new SQLMessageStore($pdo);
    }

    #[DataProvider('stores')]
    public function test_an_unknown_thread_is_empty(callable $make): void
    {
        $store = $make($this->directory);

        $this->assertSame([], $store->loadActive('thread'));
        $this->assertSame([], $store->loadAll('thread'));
    }

    #[DataProvider('stores')]
    public function test_messages_come_back_in_insertion_order_with_their_identity(callable $make): void
    {
        $store = $make($this->directory);
        $call = new ToolCall('lookup', 'call-1', ['query' => 'neuron']);
        $messages = [
            new UserMessage('Find neuron'),
            new ToolCallMessage('Searching', [$call]),
            new ToolResultMessage([(clone $call)->setResult('found')]),
            new AssistantMessage('Found it'),
        ];

        foreach ($messages as $message) {
            $store->append('thread', $message);
        }

        foreach ([$store->loadActive('thread'), $store->loadAll('thread')] as $loaded) {
            $this->assertSame($this->ids($messages), $this->ids($loaded));
            foreach ($messages as $index => $message) {
                $this->assertInstanceOf($message::class, $loaded[$index]);
                $this->assertEquals($message->jsonSerialize(), $loaded[$index]->jsonSerialize());
            }
        }
    }

    #[DataProvider('stores')]
    public function test_append_skips_a_message_already_stored_in_the_thread(callable $make): void
    {
        $store = $make($this->directory);
        $message = new UserMessage('Hello');

        $store->append('thread', $message);
        $store->append('thread', $message);

        $this->assertSame([$message->getId()], $this->ids($store->loadAll('thread')));
    }

    #[DataProvider('stores')]
    public function test_the_same_message_can_belong_to_several_threads(callable $make): void
    {
        $store = $make($this->directory);
        $message = new UserMessage('Hello');

        $store->append('first', $message);
        $store->append('second', $message);
        $store->clear('first');

        $this->assertSame([], $store->loadAll('first'));
        $this->assertSame([$message->getId()], $this->ids($store->loadActive('second')));
    }

    #[DataProvider('stores')]
    public function test_archive_takes_the_oldest_active_messages(callable $make): void
    {
        $store = $make($this->directory);
        $messages = $this->appendConversation($store, 'thread', 5);

        $store->archive('thread', 2);
        $this->assertSame($this->ids(array_slice($messages, 2)), $this->ids($store->loadActive('thread')));

        $store->archive('thread', 2);
        $this->assertSame($this->ids(array_slice($messages, 4)), $this->ids($store->loadActive('thread')));

        $this->assertSame($this->ids($messages), $this->ids($store->loadAll('thread')));
    }

    #[DataProvider('stores')]
    public function test_archive_beyond_the_active_messages_archives_them_all(callable $make): void
    {
        $store = $make($this->directory);
        $messages = $this->appendConversation($store, 'thread', 3);

        $store->archive('thread', 10);

        $this->assertSame([], $store->loadActive('thread'));
        $this->assertSame($this->ids($messages), $this->ids($store->loadAll('thread')));
    }

    #[DataProvider('stores')]
    public function test_load_all_pages_backward_from_a_cursor(callable $make): void
    {
        $store = $make($this->directory);
        $messages = $this->appendConversation($store, 'thread', 5);
        $store->archive('thread', 1);

        $latest = $store->loadAll('thread', limit: 2);
        $this->assertSame($this->ids([$messages[3], $messages[4]]), $this->ids($latest));

        $older = $store->loadAll('thread', limit: 2, before: $latest[0]->getId());
        $this->assertSame($this->ids([$messages[1], $messages[2]]), $this->ids($older));

        $oldest = $store->loadAll('thread', limit: 2, before: $older[0]->getId());
        $this->assertSame($this->ids([$messages[0]]), $this->ids($oldest));

        $this->assertSame([], $store->loadAll('thread', limit: 2, before: $messages[0]->getId()));
        $this->assertSame(
            $this->ids([$messages[0], $messages[1]]),
            $this->ids($store->loadAll('thread', before: $messages[2]->getId()))
        );
    }

    #[DataProvider('stores')]
    public function test_a_cursor_unknown_to_the_thread_yields_no_messages(callable $make): void
    {
        $store = $make($this->directory);
        $this->appendConversation($store, 'thread', 3);
        $elsewhere = $this->appendConversation($store, 'other', 1);

        $this->assertSame([], $store->loadAll('thread', limit: 2, before: 'msg_unknown'));
        $this->assertSame([], $store->loadAll('thread', limit: 2, before: $elsewhere[0]->getId()));
    }

    #[DataProvider('stores')]
    public function test_clear_removes_the_whole_thread_only(callable $make): void
    {
        $store = $make($this->directory);
        $this->appendConversation($store, 'thread', 3);
        $other = $this->appendConversation($store, 'other', 2);
        $store->archive('thread', 1);

        $store->clear('thread');

        $this->assertSame([], $store->loadActive('thread'));
        $this->assertSame([], $store->loadAll('thread'));
        $this->assertSame($this->ids($other), $this->ids($store->loadAll('other')));
    }

    /**
     * @return Message[]
     */
    protected function appendConversation(MessageStoreInterface $store, string $threadId, int $count): array
    {
        $messages = [];
        for ($index = 0; $index < $count; $index++) {
            $message = $index % 2 === 0
                ? new UserMessage("Question {$index}")
                : new AssistantMessage("Answer {$index}");
            $store->append($threadId, $message);
            $messages[] = $message;
        }

        return $messages;
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
