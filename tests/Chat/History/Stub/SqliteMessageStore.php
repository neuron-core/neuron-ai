<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Chat\History\Stub;

use NeuronAI\Chat\History\SQLMessageStore;
use PDO;

/**
 * An SQLMessageStore over its own in-memory SQLite database.
 */
class SqliteMessageStore extends SQLMessageStore
{
    public const SCHEMA = '
        CREATE TABLE chat_messages (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            thread_id VARCHAR(255) NOT NULL,
            message_id VARCHAR(64) NOT NULL,
            role VARCHAR(32) NOT NULL,
            content TEXT NULL,
            meta TEXT NULL,
            archived_at DATETIME NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE (thread_id, message_id)
        )
    ';

    public function __construct()
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec(self::SCHEMA);

        parent::__construct($pdo);
    }
}
