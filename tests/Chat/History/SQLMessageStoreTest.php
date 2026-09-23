<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Chat\History;

use NeuronAI\Chat\History\SQLMessageStore;
use NeuronAI\Exceptions\ChatHistoryException;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;

use function json_encode;

class SQLMessageStoreTest extends TestCase
{
    public function test_construction_does_not_touch_the_database(): void
    {
        $store = new SQLMessageStore(new PDO('sqlite::memory:'));

        $this->expectException(PDOException::class);
        $this->expectExceptionMessage('chat_messages');

        $store->loadActive('thread');
    }

    public function test_an_invalid_table_name_is_rejected(): void
    {
        $this->expectException(ChatHistoryException::class);

        new SQLMessageStore(new PDO('sqlite::memory:'), 'chat_messages; DROP TABLE users');
    }

    public function test_the_message_id_column_is_the_identity_of_the_record(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE chat_messages (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            thread_id VARCHAR(255) NOT NULL,
            message_id VARCHAR(64) NOT NULL,
            role VARCHAR(32) NOT NULL,
            content TEXT NULL,
            meta TEXT NULL,
            archived_at DATETIME NULL
        )');
        // A row backfilled by the upgrade: its meta predates the identity.
        $pdo->prepare('INSERT INTO chat_messages (thread_id, message_id, role, content, meta) VALUES (?, ?, ?, ?, ?)')
            ->execute(['thread', 'legacy_1', 'user', json_encode([['type' => 'text', 'content' => 'Hi']]), null]);

        $store = new SQLMessageStore($pdo);

        $this->assertSame('legacy_1', $store->loadActive('thread')[0]->getId());
        $this->assertSame('legacy_1', $store->loadAll('thread')[0]->getId());
    }
}
