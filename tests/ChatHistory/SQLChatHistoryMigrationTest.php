<?php

declare(strict_types=1);

namespace NeuronAI\Tests\ChatHistory;

use NeuronAI\Chat\History\SQLChatHistory;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Chat\Messages\Usage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Exceptions\ChatHistoryException;
use NeuronAI\Tests\Traits\CheckOpenPort;
use NeuronAI\Tools\ToolCall;
use PDO;
use PHPUnit\Framework\TestCase;
use SQLChatHistoryMigration;
use function array_map;
use function json_encode;
use function uniqid;

class SQLChatHistoryMigrationTest extends TestCase
{
    use CheckOpenPort;

    protected PDO $pdo;
    protected string $threadId;

    public function setUp(): void
    {
        if (!$this->isPortOpen('127.0.0.1', 3306)) {
            $this->markTestSkipped("MySQL not available on port 3306. Skipping test.");
        }

        $this->pdo = new PDO('mysql:host=127.0.0.1;dbname=neuron-ai', 'root', '');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // Legacy single-column structure
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS chat_history (
          id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
          thread_id VARCHAR(255) NOT NULL,
          messages LONGTEXT NOT NULL,
          created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
          updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

          UNIQUE KEY uk_thread_id (thread_id),
          INDEX idx_thread_id (thread_id)
        );");

        // New per-message structure
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS chat_messages (
          id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
          thread_id VARCHAR(255) NOT NULL,
          role VARCHAR(32) NOT NULL,
          content LONGTEXT NULL,
          meta LONGTEXT NULL,
          created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
          updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

          INDEX idx_thread_id (thread_id)
        );");

        // Remove leftover legacy threads so the migration counts are deterministic
        $this->pdo->exec("DELETE FROM chat_history");

        $this->threadId = uniqid('legacy-thread-');
    }

    protected function tearDown(): void
    {
        $stmt = $this->pdo->prepare("DELETE FROM chat_history WHERE thread_id = :thread_id");
        $stmt->execute(['thread_id' => $this->threadId]);

        $stmt = $this->pdo->prepare("DELETE FROM chat_messages WHERE thread_id = :thread_id");
        $stmt->execute(['thread_id' => $this->threadId]);
    }

    /**
     * @param Message[] $messages
     */
    protected function insertLegacyThread(array $messages): void
    {
        $stmt = $this->pdo->prepare("INSERT INTO chat_history (thread_id, messages) VALUES (:thread_id, :messages)");
        $stmt->execute([
            'thread_id' => $this->threadId,
            'messages' => json_encode(array_map(fn (Message $message): array => $message->jsonSerialize(), $messages)),
        ]);
    }

    public function test_migrates_legacy_thread_to_per_message_rows(): void
    {
        $tool = ToolCall::make('test_tool', description: 'A test tool')
            ->setInputs(['param' => 'value'])
            ->setCallId('call_123');

        $toolWithResult = ToolCall::make('test_tool', description: 'A test tool')
            ->setInputs(['param' => 'value'])
            ->setCallId('call_123')
            ->setResult('Tool result');

        $this->insertLegacyThread([
            new UserMessage('Use the tool'),
            new ToolCallMessage(tools: [$tool]),
            new ToolResultMessage([$toolWithResult]),
            (new AssistantMessage('Done!'))->setUsage(new Usage(100, 50)),
        ]);

        $migrated = (new SQLChatHistoryMigration($this->pdo))->run();

        $this->assertEquals(4, $migrated);

        // The migrated thread must load transparently through the new SQLChatHistory
        $history = new SQLChatHistory($this->pdo, $this->threadId);
        $messages = $history->getMessages();

        $this->assertCount(4, $messages);
        $this->assertInstanceOf(UserMessage::class, $messages[0]);
        $this->assertEquals('Use the tool', $messages[0]->getContent());
        $this->assertInstanceOf(ToolCallMessage::class, $messages[1]);
        $this->assertEquals('test_tool', $messages[1]->getToolCalls()[0]->getName());
        $this->assertInstanceOf(ToolResultMessage::class, $messages[2]);
        $this->assertEquals('Tool result', $messages[2]->getToolCalls()[0]->getResult());
        $this->assertInstanceOf(AssistantMessage::class, $messages[3]);
        $this->assertEquals(100, $messages[3]->getUsage()->inputTokens);
    }

    public function test_migration_is_idempotent(): void
    {
        $this->insertLegacyThread([
            new UserMessage('Hello'),
            new AssistantMessage('Hi!'),
        ]);

        $migration = new SQLChatHistoryMigration($this->pdo);

        $this->assertEquals(2, $migration->run());

        // A second run must skip the already migrated thread
        $this->assertEquals(0, $migration->run());

        $stmt = $this->pdo->prepare("SELECT COUNT(*) as count FROM chat_messages WHERE thread_id = :thread_id");
        $stmt->execute(['thread_id' => $this->threadId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $this->assertEquals(2, $result['count']);
    }

    public function test_rejects_invalid_table_name(): void
    {
        $this->expectException(ChatHistoryException::class);
        $this->expectExceptionMessage('Table not allowed');

        new SQLChatHistoryMigration($this->pdo, from: 'nonexistent_table');
    }
}
