<?php

declare(strict_types=1);

namespace NeuronAI\Chat\History;

use JsonException;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\MessageDeserializer;
use NeuronAI\Exceptions\ChatHistoryException;
use PDO;

use function array_fill;
use function array_map;
use function array_merge;
use function count;
use function implode;
use function json_decode;
use function json_encode;
use function max;
use function preg_match;
use function usort;

use const JSON_THROW_ON_ERROR;

/**
 * Stores one row per message, associated to a thread_id. Archived messages keep
 * their rows, marked by archived_at. The auto-increment id orders the thread;
 * message_id is the message identity.
 *
 * Thread and message IDs must compare exactly. On MySQL and MariaDB declare them
 * VARBINARY: the default collations ignore case and accents (MariaDB's also trailing
 * spaces), so user-Alice and user-alice would read and clear each other's messages.
 * PostgreSQL and SQLite compare VARCHAR and TEXT exactly.
 *
 * Rows are sorted by id in PHP, never by the database: MySQL resolves the thread
 * through the unique index and then filesorts, copying the large content and meta
 * columns into its sort buffer, which one big tool output is enough to overflow.
 * A page sorts only the ids, then fetches its rows by id.
 *
 * CREATE TABLE chat_messages (
 * id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 * thread_id VARBINARY(255) NOT NULL,
 * message_id VARBINARY(64) NOT NULL,
 * role VARCHAR(32) NOT NULL,
 * content LONGTEXT NULL,
 * meta LONGTEXT NULL,
 * archived_at DATETIME NULL,
 * created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
 * updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 *
 * UNIQUE INDEX idx_thread_message (thread_id, message_id)
 * );
 */
class SQLMessageStore implements MessageStoreInterface
{
    /**
     * @throws ChatHistoryException
     */
    public function __construct(
        protected PDO $pdo,
        protected string $table = 'chat_messages',
    ) {
        // Identifiers cannot be bound as parameters: the format check keeps the name safe to interpolate.
        if (preg_match('/^[a-zA-Z_]\w*$/D', $table) !== 1) {
            throw new ChatHistoryException("Invalid table name '{$table}'.");
        }
    }

    public function loadActive(string $threadId): array
    {
        return $this->loadInKeyOrder(
            'thread_id = :thread_id AND archived_at IS NULL',
            ['thread_id' => $threadId]
        );
    }

    public function loadAll(string $threadId, ?int $limit = null, ?string $before = null): array
    {
        $conditions = 'thread_id = :thread_id';
        $parameters = ['thread_id' => $threadId];

        if ($before !== null) {
            $conditions .= " AND id < (SELECT id FROM {$this->table} WHERE thread_id = :cursor_thread_id AND message_id = :before)";
            $parameters += ['cursor_thread_id' => $threadId, 'before' => $before];
        }

        if ($limit === null) {
            return $this->loadInKeyOrder($conditions, $parameters);
        }

        // A page is the newest rows before the cursor, returned in insertion order; a negative limit is an empty one.
        $stmt = $this->pdo->prepare("SELECT id FROM {$this->table} WHERE {$conditions} ORDER BY id DESC LIMIT " . max(0, $limit));
        $stmt->execute($parameters);
        $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);

        if ($ids === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        return $this->loadInKeyOrder("id IN ({$placeholders})", $ids);
    }

    /**
     * @throws JsonException
     */
    public function append(string $threadId, Message $message): void
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO {$this->table} (thread_id, message_id, role, content, meta) VALUES (:thread_id, :message_id, :role, :content, :meta) {$this->skipDuplicateClause()}"
        );
        $stmt->execute([
            'thread_id' => $threadId,
            'message_id' => $message->getId(),
            ...$this->serializeMessage($message),
        ]);
    }

    /**
     * The unique (thread_id, message_id) index detects a message already stored, and
     * the conflict clause of the database skips it. The portable alternative, INSERT
     * ... WHERE NOT EXISTS, deadlocks concurrent writers on MySQL and MariaDB.
     */
    protected function skipDuplicateClause(): string
    {
        return $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql'
            ? 'ON DUPLICATE KEY UPDATE message_id = message_id'
            : 'ON CONFLICT DO NOTHING';
    }

    public function archive(string $threadId, int $count): void
    {
        if ($count <= 0) {
            return;
        }

        $stmt = $this->pdo->prepare(
            "SELECT id FROM {$this->table} WHERE thread_id = :thread_id AND archived_at IS NULL ORDER BY id LIMIT {$count}"
        );
        $stmt->execute(['thread_id' => $threadId]);
        $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);

        if ($ids === []) {
            return;
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->pdo->prepare(
            "UPDATE {$this->table} SET archived_at = CURRENT_TIMESTAMP WHERE id IN ({$placeholders})"
        );
        $stmt->execute($ids);
    }

    public function clear(string $threadId): void
    {
        $stmt = $this->pdo->prepare("DELETE FROM {$this->table} WHERE thread_id = :thread_id");
        $stmt->execute(['thread_id' => $threadId]);
    }

    /**
     * @param array<int|string, mixed> $parameters
     * @return Message[]
     */
    protected function loadInKeyOrder(string $conditions, array $parameters): array
    {
        $stmt = $this->pdo->prepare("SELECT id, message_id, role, content, meta FROM {$this->table} WHERE {$conditions}");
        $stmt->execute($parameters);
        $records = $stmt->fetchAll(PDO::FETCH_ASSOC);

        usort($records, fn (array $a, array $b): int => $a['id'] <=> $b['id']);

        return $this->deserialize($records);
    }

    /**
     * @param array<int, array<string, mixed>> $records
     * @return Message[]
     */
    protected function deserialize(array $records): array
    {
        $deserializer = new MessageDeserializer();

        return array_map(
            fn (array $record): Message => $deserializer->deserialize($this->recordToArray($record)),
            $records
        );
    }

    /**
     * The message_id column is the identity of the record.
     *
     * @param array<string, mixed> $record
     * @return array<string, mixed>
     */
    protected function recordToArray(array $record): array
    {
        $meta = $record['meta'] !== null ? (array) json_decode((string) $record['meta'], true) : [];

        return array_merge($meta, [
            'role' => $record['role'],
            'content' => $record['content'] !== null ? json_decode((string) $record['content'], true) : null,
            '__id' => $record['message_id'],
        ]);
    }

    /**
     * Split a message into the role, content, and meta columns.
     *
     * @return array{role: string, content: string|null, meta: string|null}
     * @throws JsonException
     */
    protected function serializeMessage(Message $message): array
    {
        $data = $message->jsonSerialize();

        $content = $data['content'] ?? null;

        // Remove fields that are stored in separate columns
        unset($data['role'], $data['content']);

        return [
            'role' => $message->getRole(),
            'content' => $content !== null ? json_encode($content, JSON_THROW_ON_ERROR) : null,
            'meta' => $data === [] ? null : json_encode($data, JSON_THROW_ON_ERROR),
        ];
    }
}
