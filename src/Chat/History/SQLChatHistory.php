<?php

declare(strict_types=1);

namespace NeuronAI\Chat\History;

use NeuronAI\Chat\Messages\Message;
use NeuronAI\Exceptions\ChatHistoryException;
use PDO;

use function array_fill;
use function array_map;
use function array_merge;
use function count;
use function implode;
use function in_array;
use function json_decode;
use function json_encode;
use function preg_match;
use function trim;

/**
 * Stores one row per message, associated to a thread_id.
 *
 * CREATE TABLE chat_messages (
 * id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 * thread_id VARCHAR(255) NOT NULL,
 * role VARCHAR(32) NOT NULL,
 * content LONGTEXT NULL,
 * meta LONGTEXT NULL,
 * created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
 * updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 *
 * INDEX idx_thread_id (thread_id)
 * );
 *
 */
class SQLChatHistory extends AbstractChatHistory
{
    protected string $table;

    public function __construct(
        protected string $thread_id,
        protected PDO $pdo,
        string $table = 'chat_messages',
        int $contextWindow = 50000
    ) {
        parent::__construct($contextWindow);
        $this->table = $this->sanitizeTableName($table);
        $this->load();
    }

    public function getThreadId(): string
    {
        return $this->thread_id;
    }

    protected function load(): void
    {
        $stmt = $this->pdo->prepare("SELECT role, content, meta FROM {$this->table} WHERE thread_id = :thread_id ORDER BY id");
        $stmt->execute(['thread_id' => $this->thread_id]);
        $records = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (!empty($records)) {
            $this->history = $this->deserializeMessages(
                array_map($this->recordToArray(...), $records)
            );
        }
    }

    protected function onNewMessage(Message $message): void
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO {$this->table} (thread_id, role, content, meta) VALUES (:thread_id, :role, :content, :meta)"
        );
        $stmt->execute(array_merge(
            ['thread_id' => $this->thread_id],
            $this->serializeMessage($message)
        ));
    }

    protected function onTrimHistory(int $index): void
    {
        if ($index <= 0) {
            return;
        }

        // Delete the first $index messages of the thread.
        $stmt = $this->pdo->prepare("SELECT id FROM {$this->table} WHERE thread_id = :thread_id ORDER BY id LIMIT {$index}");
        $stmt->execute(['thread_id' => $this->thread_id]);
        $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);

        if (empty($ids)) {
            return;
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->pdo->prepare("DELETE FROM {$this->table} WHERE id IN ({$placeholders})");
        $stmt->execute($ids);
    }

    protected function clear(): void
    {
        $stmt = $this->pdo->prepare("DELETE FROM {$this->table} WHERE thread_id = :thread_id");
        $stmt->execute(['thread_id' => $this->thread_id]);
    }

    /**
     * Convert a database record to the array format expected by deserializeMessages.
     *
     * @param array<string, mixed> $record
     * @return array<string, mixed>
     */
    protected function recordToArray(array $record): array
    {
        $data = [
            'role' => $record['role'],
            'content' => $record['content'] !== null ? json_decode((string) $record['content'], true) : null,
        ];

        // Merge the "meta" field if present
        if ($record['meta'] !== null && ($meta = json_decode((string) $record['meta'], true))) {
            return array_merge($data, (array) $meta);
        }

        return $data;
    }

    /**
     * Split a message into the role, content, and meta columns.
     *
     * @return array{role: string, content: string|null, meta: string|null}
     */
    protected function serializeMessage(Message $message): array
    {
        $data = $message->jsonSerialize();

        $content = $data['content'] ?? null;

        // Remove fields that are stored in separate columns
        unset($data['role'], $data['content']);

        return [
            'role' => $message->getRole(),
            'content' => $content !== null ? json_encode($content) : null,
            'meta' => $data === [] ? null : json_encode($data),
        ];
    }

    protected function sanitizeTableName(string $tableName): string
    {
        $tableName = trim($tableName);

        // Whitelist validation
        if (!$this->tableExists($tableName)) {
            throw new ChatHistoryException('Table not allowed');
        }

        // Format validation as backup
        if (in_array(preg_match('/^[a-zA-Z_]\w*$/', $tableName), [0, false], true)) {
            throw new ChatHistoryException('Invalid table name format');
        }

        return $tableName;
    }

    protected function tableExists(string $tableName): bool
    {
        $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        $query = match ($driver) {
            'mysql' => "SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :table_name",
            'pgsql' => "SELECT 1 FROM information_schema.tables WHERE table_catalog = current_database() AND table_name = :table_name",
            'sqlite' => "SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = :table_name",
            default => throw new ChatHistoryException("Unsupported database driver: {$driver}"),
        };

        $stmt = $this->pdo->prepare($query);
        $stmt->execute(['table_name' => $tableName]);
        return $stmt->fetch() !== false;
    }
}
