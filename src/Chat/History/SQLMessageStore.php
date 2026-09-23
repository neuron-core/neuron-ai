<?php

declare(strict_types=1);

namespace NeuronAI\Chat\History;

use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\MessageDeserializer;
use NeuronAI\Exceptions\ChatHistoryException;
use PDO;

use function array_fill;
use function array_map;
use function array_merge;
use function array_reverse;
use function count;
use function implode;
use function json_decode;
use function json_encode;
use function preg_match;

/**
 * Stores one row per message, associated to a thread_id. Archived messages keep
 * their rows, marked by archived_at. The auto-increment id orders the thread;
 * message_id is the message identity.
 *
 * CREATE TABLE chat_messages (
 * id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 * thread_id VARCHAR(255) NOT NULL,
 * message_id VARCHAR(64) NOT NULL,
 * role VARCHAR(32) NOT NULL,
 * content LONGTEXT NULL,
 * meta LONGTEXT NULL,
 * archived_at DATETIME NULL,
 * created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
 * updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 *
 * INDEX idx_thread_id (thread_id),
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
        if (preg_match('/^[a-zA-Z_]\w*$/', $table) !== 1) {
            throw new ChatHistoryException("Invalid table name '{$table}'.");
        }
    }

    public function loadActive(string $threadId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT message_id, role, content, meta FROM {$this->table} WHERE thread_id = :thread_id AND archived_at IS NULL ORDER BY id"
        );
        $stmt->execute(['thread_id' => $threadId]);

        return $this->deserialize($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function loadAll(string $threadId, ?int $limit = null, ?string $before = null): array
    {
        $conditions = 'thread_id = :thread_id';
        $parameters = ['thread_id' => $threadId];

        if ($before !== null) {
            $conditions .= " AND id < (SELECT id FROM {$this->table} WHERE thread_id = :cursor_thread_id AND message_id = :before)";
            $parameters += ['cursor_thread_id' => $threadId, 'before' => $before];
        }

        // A page is the newest rows before the cursor, returned in insertion order.
        $order = $limit === null ? 'ORDER BY id' : "ORDER BY id DESC LIMIT {$limit}";

        $stmt = $this->pdo->prepare("SELECT message_id, role, content, meta FROM {$this->table} WHERE {$conditions} {$order}");
        $stmt->execute($parameters);
        $records = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return $this->deserialize($limit === null ? $records : array_reverse($records));
    }

    public function append(string $threadId, Message $message): void
    {
        $stmt = $this->pdo->prepare(
            "SELECT 1 FROM {$this->table} WHERE thread_id = :thread_id AND message_id = :message_id"
        );
        $stmt->execute(['thread_id' => $threadId, 'message_id' => $message->getId()]);

        if ($stmt->fetch() !== false) {
            return;
        }

        $stmt = $this->pdo->prepare(
            "INSERT INTO {$this->table} (thread_id, message_id, role, content, meta) VALUES (:thread_id, :message_id, :role, :content, :meta)"
        );
        $stmt->execute([
            'thread_id' => $threadId,
            'message_id' => $message->getId(),
            ...$this->serializeMessage($message),
        ]);
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
}
