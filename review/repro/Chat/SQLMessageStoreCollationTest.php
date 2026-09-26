<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Chat\History;

use NeuronAI\Chat\History\SQLMessageStore;
use NeuronAI\Chat\Messages\UserMessage;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * The store isolates threads only as far as the column collation compares exactly.
 * SQLite NOCASE stands in for the case-insensitive collation MySQL/MariaDB apply by
 * default to the schema documented on SQLMessageStore (no MySQL server is available).
 */
class SQLMessageStoreCollationTest extends TestCase
{
    protected function storeWithThreadCollation(string $collation): SQLMessageStore
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec("CREATE TABLE chat_messages (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            thread_id VARCHAR(255) COLLATE {$collation} NOT NULL,
            message_id VARCHAR(64) COLLATE {$collation} NOT NULL,
            role VARCHAR(32) NOT NULL,
            content TEXT NULL,
            meta TEXT NULL,
            archived_at DATETIME NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE (thread_id, message_id)
        )");

        return new SQLMessageStore($pdo);
    }

    public function test_threads_differing_only_by_case_stay_isolated_under_the_documented_mysql_collation(): void
    {
        $store = $this->storeWithThreadCollation('NOCASE');
        $store->append('user-Alice', new UserMessage('alice secret'));

        $this->assertSame([], $store->loadActive('user-alice'));

        $store->clear('user-alice');
        $this->assertCount(1, $store->loadActive('user-Alice'));
    }

    public function test_threads_differing_only_by_case_stay_isolated_under_a_binary_collation(): void
    {
        $store = $this->storeWithThreadCollation('BINARY');
        $store->append('user-Alice', new UserMessage('alice secret'));

        $this->assertSame([], $store->loadActive('user-alice'));

        $store->clear('user-alice');
        $this->assertCount(1, $store->loadActive('user-Alice'));
    }
}
