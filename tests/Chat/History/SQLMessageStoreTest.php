<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Chat\History;

use NeuronAI\Chat\History\SQLMessageStore;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Usage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Exceptions\ChatHistoryException;
use NeuronAI\Tests\Chat\History\Stub\SqliteMessageStore;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function json_decode;
use function json_encode;
use function str_replace;

class SQLMessageStoreTest extends TestCase
{
    public function test_construction_does_not_touch_the_database(): void
    {
        $store = new SQLMessageStore(new PDO('sqlite::memory:'));

        $this->expectException(PDOException::class);
        $this->expectExceptionMessage('chat_messages');

        $store->loadActive('thread');
    }

    /**
     * Identifiers are interpolated in the SQL, so anything but a plain name is refused.
     *
     * @return array<string, array{string}>
     */
    public static function invalidTableNames(): array
    {
        return [
            'statement injection' => ['chat_messages; DROP TABLE users'],
            'comment' => ['chat_messages--'],
            'space' => ['chat messages'],
            'leading digit' => ['1chat'],
            'empty' => [''],
            'quoted' => ['"chat_messages"'],
            'backticks' => ['`chat_messages`'],
            'schema qualified' => ['public.chat_messages'],
            'subquery' => ['(SELECT 1)'],
            'dash' => ['chat-messages'],
            'unicode letter' => ['chàt'],
            'leading newline' => ["\nchat_messages"],
        ];
    }

    #[DataProvider('invalidTableNames')]
    public function test_an_invalid_table_name_is_rejected(string $table): void
    {
        $this->expectException(ChatHistoryException::class);
        $this->expectExceptionMessage("Invalid table name '{$table}'.");

        new SQLMessageStore(new PDO('sqlite::memory:'), $table);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function validTableNames(): array
    {
        return [
            'default' => ['chat_messages'],
            'leading underscore' => ['_history'],
            'mixed case and digits' => ['Chat2Messages'],
            'single letter' => ['h'],
        ];
    }

    #[DataProvider('validTableNames')]
    public function test_a_plain_table_name_is_used_for_every_statement(string $table): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec(str_replace('CREATE TABLE chat_messages', "CREATE TABLE {$table}", SqliteMessageStore::SCHEMA));
        $store = new SQLMessageStore($pdo, $table);
        $message = new UserMessage('Hello');

        $store->append('thread', $message);
        $store->archive('thread', 1);

        $this->assertSame($message->getId(), $store->loadAll('thread', limit: 1)[0]->getId());
        $this->assertSame([], $store->loadActive('thread'));
        $store->clear('thread');
        $this->assertSame('0', (string) $pdo->query("SELECT COUNT(*) FROM {$table}")->fetchColumn());
    }

    public function test_a_message_is_split_into_its_columns(): void
    {
        $pdo = $this->database();
        $message = (new AssistantMessage('Hi'))->setUsage(new Usage(10, 5))->addMetadata('source', 'web');

        (new SQLMessageStore($pdo))->append("thread'; --", $message);

        $row = $pdo->query('SELECT thread_id, message_id, role, content, meta, archived_at FROM chat_messages')->fetch(PDO::FETCH_ASSOC);
        $this->assertSame("thread'; --", $row['thread_id']);
        $this->assertSame($message->getId(), $row['message_id']);
        $this->assertSame('assistant', $row['role']);
        $this->assertSame([['type' => 'text', 'content' => 'Hi', 'meta' => []]], json_decode((string) $row['content'], true));
        $this->assertSame([
            '__id' => $message->getId(),
            'usage' => ['input_tokens' => 10, 'output_tokens' => 5, 'cached_input_tokens' => 0, 'reasoning_tokens' => 0],
            '__meta' => ['source' => 'web'],
        ], json_decode((string) $row['meta'], true));
        $this->assertNull($row['archived_at']);
    }

    public function test_archival_marks_the_rows_instead_of_deleting_them(): void
    {
        $pdo = $this->database();
        $store = new SQLMessageStore($pdo);
        $store->append('thread', new UserMessage('Hello'));
        $store->append('thread', new AssistantMessage('Hi'));

        $store->archive('thread', 1);

        $rows = $pdo->query('SELECT role, archived_at FROM chat_messages ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        $this->assertCount(2, $rows);
        $this->assertNotNull($rows[0]['archived_at']);
        $this->assertNull($rows[1]['archived_at']);
    }

    public function test_the_message_id_column_is_the_identity_of_the_record(): void
    {
        $pdo = $this->database();
        // A row backfilled by the upgrade: its meta predates the identity.
        $this->insert($pdo, 'legacy_1', json_encode([['type' => 'text', 'content' => 'Hi']]), null);

        $store = new SQLMessageStore($pdo);

        $this->assertSame('legacy_1', $store->loadActive('thread')[0]->getId());
        $this->assertSame('legacy_1', $store->loadAll('thread')[0]->getId());
    }

    public function test_the_message_id_column_wins_over_an_identity_in_the_flat_meta(): void
    {
        $pdo = $this->database();
        $this->insert($pdo, 'row_identity', null, json_encode(['__id' => 'meta_identity', 'stop_reason' => 'end_turn']));

        $loaded = (new SQLMessageStore($pdo))->loadAll('thread')[0];

        $this->assertSame('row_identity', $loaded->getId());
        $this->assertSame('end_turn', $loaded->getMetadata('stop_reason'));
    }

    public function test_a_row_without_content_or_meta_loads_as_an_empty_message(): void
    {
        $pdo = $this->database();
        $this->insert($pdo, 'msg_empty', null, null);

        $loaded = (new SQLMessageStore($pdo))->loadActive('thread');

        $this->assertCount(1, $loaded);
        $this->assertSame([], $loaded[0]->getContentBlocks());
        $this->assertNull($loaded[0]->getUsage());
    }

    protected function database(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec(SqliteMessageStore::SCHEMA);

        return $pdo;
    }

    protected function insert(PDO $pdo, string $messageId, ?string $content, ?string $meta): void
    {
        $pdo->prepare('INSERT INTO chat_messages (thread_id, message_id, role, content, meta) VALUES (?, ?, ?, ?, ?)')
            ->execute(['thread', $messageId, 'user', $content, $meta]);
    }
}
