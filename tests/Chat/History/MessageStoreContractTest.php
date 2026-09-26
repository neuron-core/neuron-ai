<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Chat\History;

use Illuminate\Database\Capsule\Manager as Capsule;
use NeuronAI\Chat\History\EloquentMessageStore;
use NeuronAI\Chat\History\FileMessageStore;
use NeuronAI\Chat\History\InMemoryMessageStore;
use NeuronAI\Chat\History\MessageStoreInterface;
use NeuronAI\Chat\History\SQLMessageStore;
use NeuronAI\Chat\Enums\MediaType;
use NeuronAI\Chat\Enums\SourceType;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Citation;
use NeuronAI\Chat\Messages\ContentBlocks\AudioContent;
use NeuronAI\Chat\Messages\ContentBlocks\FileContent;
use NeuronAI\Chat\Messages\ContentBlocks\ImageContent;
use NeuronAI\Chat\Messages\ContentBlocks\ReasoningContent;
use NeuronAI\Chat\Messages\ContentBlocks\TextContent;
use NeuronAI\Chat\Messages\ContentBlocks\VideoContent;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Chat\Messages\Usage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Tests\Chat\History\Stub\ChatMessage;
use NeuronAI\Tests\Chat\History\Stub\PostgresMessageStore;
use NeuronAI\Tests\Chat\History\Stub\SqliteMessageStore;
use NeuronAI\Tools\ApprovalState;
use NeuronAI\Tools\ToolCall;
use NeuronAI\Tools\ToolOutput;
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

    /**
     * @var PostgresMessageStore[]
     */
    protected static array $postgresStores = [];

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/neuron_messages_' . uniqid();
    }

    protected function tearDown(): void
    {
        foreach (self::$postgresStores as $store) {
            $store->drop();
        }
        self::$postgresStores = [];

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
            'pgsql' => [fn (string $directory): MessageStoreInterface => self::$postgresStores[] = PostgresMessageStore::open()],
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
    public function test_metadata_survives_whatever_its_name_or_value(callable $make): void
    {
        $store = $make($this->directory);
        $metadata = [
            'type' => 'tool_call',
            'role' => 'pinned',
            'content' => 'note',
            'tools' => ['search'],
            'archived_at' => 'never',
            'attempt' => 2,
            'score' => 0.5,
            'flagged' => true,
        ];
        $store->append('thread', (new UserMessage('Hello'))->setMetadata($metadata));

        foreach ([$store->loadActive('thread'), $store->loadAll('thread')] as $loaded) {
            $this->assertCount(1, $loaded);
            $this->assertInstanceOf(UserMessage::class, $loaded[0]);
            foreach ($metadata as $key => $value) {
                $this->assertSame($value, $loaded[0]->getMetadata($key));
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
    public function test_append_keeps_the_first_message_stored_under_an_id(callable $make): void
    {
        $store = $make($this->directory);
        $store->append('thread', (new UserMessage('Original'))->setId('msg_1'));
        $store->append('thread', new AssistantMessage('Answer'));

        $store->append('thread', (new UserMessage('Replayed with other content'))->setId('msg_1'));

        $loaded = $store->loadAll('thread');
        $this->assertCount(2, $loaded);
        $this->assertSame('msg_1', $loaded[0]->getId());
        $this->assertSame('Original', $loaded[0]->getContent());
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

    #[DataProvider('stores')]
    public function test_rich_messages_survive_the_store(callable $make): void
    {
        $store = $make($this->directory);
        $call = new ToolCall('chart', 'call-1', ['symbol' => 'AAPL', 'range' => ['from' => 1, 'to' => 2]], 'Render a chart');
        $call->setApprovalState(ApprovalState::Pending)->setApprovalReason('Costs credits');
        $settled = (clone $call)->setApprovalState(ApprovalState::Approved)->setResult(new ToolOutput([
            new TextContent('Chart for AAPL'),
            new ImageContent('aGVsbG8=', SourceType::BASE64, MediaType::PNG),
        ]));
        $failed = (new ToolCall('mail', 'call-2', ['to' => 'ops@example.com'], deferred: true))->setResult(ToolOutput::error('SMTP refused'));
        $messages = [
            new UserMessage([
                (new TextContent("Ciao 👋 — 日本語 \"quoted\" \\ back\nnew line"))->addMetadata('cache_control', ['type' => 'ephemeral']),
                new ImageContent('https://example.com/a.png?x=1&y=2', SourceType::URL, MediaType::PNG),
                new FileContent('file_123', SourceType::ID, 'application/x-custom', 'rapport é.pdf'),
                new AudioContent('UklGRg==', SourceType::BASE64, MediaType::WAV),
                new VideoContent('https://example.com/a.mp4', SourceType::URL),
            ]),
            (new ToolCallMessage([new ReasoningContent('Need a chart', 'rs_1')], [$call]))
                ->setUsage(new Usage(120, 30, 100, 12))
                ->addMetadata('citations', [new Citation('c1', 'doc.pdf', 'Doc', 0, 5, 'Neuron', ['page' => 3])]),
            new ToolResultMessage([$settled, $failed]),
            (new AssistantMessage("It's up 5% 📈"))->setStopReason('end_turn')->addMetadata('nested', ['a' => [1, 2.5, null, false]]),
        ];

        foreach ($messages as $message) {
            $store->append('thread', $message);
        }

        foreach ([$store->loadActive('thread'), $store->loadAll('thread')] as $loaded) {
            $this->assertCount(4, $loaded);
            foreach ($messages as $index => $message) {
                $this->assertSame($message::class, $loaded[$index]::class);
                $this->assertEquals($message->jsonSerialize(), $loaded[$index]->jsonSerialize());
            }
        }
    }

    #[DataProvider('stores')]
    public function test_insertion_order_does_not_depend_on_the_message_ids(callable $make): void
    {
        $store = $make($this->directory);
        $messages = [
            (new UserMessage('first'))->setId('msg_z'),
            (new AssistantMessage('second'))->setId('msg_m'),
            (new UserMessage('third'))->setId('msg_a'),
        ];
        foreach ($messages as $message) {
            $store->append('thread', $message);
        }

        $this->assertSame(['msg_z', 'msg_m', 'msg_a'], $this->ids($store->loadAll('thread')));
        $this->assertSame(['msg_m', 'msg_a'], $this->ids($store->loadAll('thread', limit: 2)));
        $this->assertSame(['msg_z'], $this->ids($store->loadAll('thread', limit: 5, before: 'msg_m')));

        $store->archive('thread', 1);
        $this->assertSame(['msg_m', 'msg_a'], $this->ids($store->loadActive('thread')));
    }

    #[DataProvider('stores')]
    public function test_a_limit_of_zero_yields_no_messages(callable $make): void
    {
        $store = $make($this->directory);
        $messages = $this->appendConversation($store, 'thread', 3);

        $this->assertSame([], $store->loadAll('thread', limit: 0));
        $this->assertSame([], $store->loadAll('thread', limit: 0, before: $messages[2]->getId()));
    }

    #[DataProvider('stores')]
    public function test_a_limit_beyond_the_thread_yields_it_whole(callable $make): void
    {
        $store = $make($this->directory);
        $messages = $this->appendConversation($store, 'thread', 3);

        $this->assertSame($this->ids($messages), $this->ids($store->loadAll('thread', limit: 4)));
        $this->assertSame($this->ids($messages), $this->ids($store->loadAll('thread', limit: 100)));
        $this->assertSame(
            $this->ids(array_slice($messages, 0, 2)),
            $this->ids($store->loadAll('thread', limit: 100, before: $messages[2]->getId()))
        );
    }

    #[DataProvider('stores')]
    public function test_archiving_nothing_changes_nothing(callable $make): void
    {
        $store = $make($this->directory);
        $messages = $this->appendConversation($store, 'thread', 3);

        $store->archive('thread', 0);
        $store->archive('unknown', 2);

        $this->assertSame($this->ids($messages), $this->ids($store->loadActive('thread')));
        $this->assertSame([], $store->loadAll('unknown'));
    }

    #[DataProvider('stores')]
    public function test_archiving_before_the_first_message_does_not_archive_it(callable $make): void
    {
        $store = $make($this->directory);
        $store->archive('thread', 2);

        $messages = $this->appendConversation($store, 'thread', 2);

        $this->assertSame($this->ids($messages), $this->ids($store->loadActive('thread')));
    }

    #[DataProvider('stores')]
    public function test_archiving_is_per_thread(callable $make): void
    {
        $store = $make($this->directory);
        $this->appendConversation($store, 'thread', 2);
        $other = $this->appendConversation($store, 'other', 2);

        $store->archive('thread', 2);

        $this->assertSame([], $store->loadActive('thread'));
        $this->assertSame($this->ids($other), $this->ids($store->loadActive('other')));
    }

    #[DataProvider('stores')]
    public function test_an_archived_message_is_not_stored_again(callable $make): void
    {
        $store = $make($this->directory);
        $message = new UserMessage('Hello');
        $store->append('thread', $message);
        $store->archive('thread', 1);

        $store->append('thread', $message);

        $this->assertSame([], $store->loadActive('thread'));
        $this->assertSame([$message->getId()], $this->ids($store->loadAll('thread')));
    }

    #[DataProvider('stores')]
    public function test_a_cleared_thread_starts_afresh(callable $make): void
    {
        $store = $make($this->directory);
        $this->appendConversation($store, 'thread', 3);
        $store->archive('thread', 2);
        $store->clear('thread');

        $messages = $this->appendConversation($store, 'thread', 2);

        $this->assertSame($this->ids($messages), $this->ids($store->loadActive('thread')));
        $this->assertSame($this->ids($messages), $this->ids($store->loadAll('thread')));
    }

    #[DataProvider('stores')]
    public function test_clearing_an_unknown_thread_is_harmless(callable $make): void
    {
        $store = $make($this->directory);
        $messages = $this->appendConversation($store, 'thread', 1);

        $store->clear('unknown');

        $this->assertSame($this->ids($messages), $this->ids($store->loadAll('thread')));
    }

    #[DataProvider('stores')]
    public function test_hostile_thread_ids_are_stored_verbatim_and_kept_apart(callable $make): void
    {
        $store = $make($this->directory);
        $threads = [
            '',
            "'; DROP TABLE chat_messages; --",
            '" OR 1=1 --',
            '../../../etc/passwd',
            '..\\..\\windows',
            '%',
            '_',
            'a b',
            'a%20b',
            '日本語 👋',
        ];

        $messages = [];
        foreach ($threads as $thread) {
            $messages[$thread] = new UserMessage("In thread {$thread}");
            $store->append($thread, $messages[$thread]);
        }

        foreach ($threads as $thread) {
            $loaded = $store->loadAll($thread);
            $this->assertSame([$messages[$thread]->getId()], $this->ids($loaded), "Thread '{$thread}'");
            $this->assertSame("In thread {$thread}", $loaded[0]->getContent());
        }

        $store->clear('%');
        $store->clear('_');
        $this->assertCount(1, $store->loadAll('a b'));
        $this->assertCount(1, $store->loadAll("'; DROP TABLE chat_messages; --"));
    }

    #[DataProvider('stores')]
    public function test_a_message_id_is_bound_and_never_interpreted(callable $make): void
    {
        $store = $make($this->directory);
        $first = (new UserMessage('first'))->setId("msg_' OR '1'='1");
        $second = (new AssistantMessage('second'))->setId('msg_%');
        $store->append('thread', $first);
        $store->append('thread', $second);

        $this->assertSame([$first->getId()], $this->ids($store->loadAll('thread', limit: 5, before: $second->getId())));
        $this->assertSame([], $store->loadAll('thread', limit: 5, before: "msg_' OR '1'='1' --"));
    }

    #[DataProvider('stores')]
    public function test_falsy_or_numeric_message_ids_are_exact_cursors(callable $make): void
    {
        $store = $make($this->directory);
        $messages = [
            (new UserMessage('first'))->setId('0'),
            (new AssistantMessage('second'))->setId(''),
            (new UserMessage('third'))->setId('00'),
            new AssistantMessage('fourth'),
        ];
        foreach ($messages as $message) {
            $store->append('thread', $message);
        }

        $this->assertSame(['0'], $this->ids($store->loadAll('thread', before: '')));
        $this->assertSame([], $this->ids($store->loadAll('thread', limit: 5, before: '0')));
        $this->assertSame(['0', ''], $this->ids($store->loadAll('thread', limit: 5, before: '00')));
        $this->assertSame(['0', '', '00'], $this->ids($store->loadAll('thread', limit: 5, before: $messages[3]->getId())));
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
