<?php

declare(strict_types=1);

namespace NeuronAI\Tests\ChatHistory;

use NeuronAI\Chat\History\ChatHistoryInterface;
use NeuronAI\Chat\History\SQLChatHistory;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ContentBlocks\TextContent;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Exceptions\ChatHistoryException;
use NeuronAI\Tests\Traits\CheckOpenPort;
use NeuronAI\Tools\Tool;
use PHPUnit\Framework\TestCase;
use PDO;

use function count;
use function json_decode;
use function uniqid;

class SQLChatHistoryTest extends TestCase
{
    use CheckOpenPort;

    protected ChatHistoryInterface $history;
    protected PDO $pdo;
    protected string $threadId;

    public function setUp(): void
    {
        if (!$this->isPortOpen('127.0.0.1', 3306)) {
            $this->markTestSkipped("MySQL not available on port 3306. Skipping test.");
        }

        $this->pdo = new PDO('mysql:host=127.0.0.1;dbname=neuron-ai', 'root', '');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS chat_history (
          id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
          thread_id VARCHAR(255) NOT NULL,
          messages LONGTEXT NOT NULL,
          created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
          updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

          UNIQUE KEY uk_thread_id (thread_id),
          INDEX idx_thread_id (thread_id)
        );");

        $this->threadId = uniqid('test-thread-');

        $this->history = new SQLChatHistory($this->threadId, $this->pdo);
    }

    protected function tearDown(): void
    {
        $this->history->flushAll();
    }

    public function test_creates_chat_history_instance(): void
    {
        $this->assertInstanceOf(ChatHistoryInterface::class, $this->history);
        $this->assertInstanceOf(SQLChatHistory::class, $this->history);
    }

    public function test_initializes_empty_thread_in_database(): void
    {
        $stmt = $this->pdo->prepare("SELECT * FROM chat_history WHERE thread_id = :thread_id");
        $stmt->execute(['thread_id' => $this->threadId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        $this->assertNotFalse($row, 'Thread should exist in database');
        $this->assertEquals($this->threadId, $row['thread_id']);
        $this->assertEquals('[]', $row['messages']);
    }

    public function test_persists_message_to_database(): void
    {
        $message = new UserMessage('Hello from SQL!');
        $this->history->addMessage($message);

        // Verify in database
        $stmt = $this->pdo->prepare("SELECT messages FROM chat_history WHERE thread_id = :thread_id");
        $stmt->execute(['thread_id' => $this->threadId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        $this->assertNotFalse($result);
        $messages = json_decode((string) $result['messages'], true);
        $this->assertIsArray($messages);
        $this->assertCount(1, $messages);
        $this->assertEquals('user', $messages[0]['role']);
    }

    public function test_loads_existing_thread_from_database(): void
    {
        // Add messages to the thread
        $this->history->addMessage(new UserMessage('First message'));
        $this->history->addMessage(new AssistantMessage('Second message'));

        // Create a new instance with the same thread_id
        $newHistory = new SQLChatHistory($this->threadId, $this->pdo);

        // Should load existing messages
        $messages = $newHistory->getMessages();
        $this->assertCount(2, $messages);
        $this->assertInstanceOf(UserMessage::class, $messages[0]);
        $this->assertInstanceOf(AssistantMessage::class, $messages[1]);
    }

    public function test_updates_messages_on_add(): void
    {
        $this->history->addMessage(new UserMessage('Message 1'));

        $stmt = $this->pdo->prepare("SELECT messages FROM chat_history WHERE thread_id = :thread_id");
        $stmt->execute(['thread_id' => $this->threadId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $messages1 = json_decode((string) $result['messages'], true);
        $this->assertCount(1, $messages1);

        $this->history->addMessage(new AssistantMessage('Message 2'));

        $stmt->execute(['thread_id' => $this->threadId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $messages2 = json_decode((string) $result['messages'], true);
        $this->assertCount(2, $messages2);
    }

    public function test_flush_all_removes_thread_from_database(): void
    {
        $this->history->addMessage(new UserMessage('Test message'));

        // Verify thread exists
        $stmt = $this->pdo->prepare("SELECT COUNT(*) as count FROM chat_history WHERE thread_id = :thread_id");
        $stmt->execute(['thread_id' => $this->threadId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $this->assertEquals(1, $result['count']);

        // Flush
        $this->history->flushAll();

        // Verify thread is removed
        $stmt->execute(['thread_id' => $this->threadId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $this->assertEquals(0, $result['count']);
    }

    public function test_persists_tool_calls_and_results(): void
    {
        $tool = Tool::make('test_tool', 'A test tool')
            ->setInputs(['param' => 'value'])
            ->setCallId('call_123');

        $toolWithResult = Tool::make('test_tool', 'A test tool')
            ->setInputs(['param' => 'value'])
            ->setCallId('call_123')
            ->setResult('Tool result');

        $this->history->addMessage(new UserMessage('Use the tool'));
        $this->history->addMessage(new ToolCallMessage(tools: [$tool]));
        $this->history->addMessage(new ToolResultMessage([$toolWithResult]));

        // Create new instance and verify tool messages are loaded correctly
        $newHistory = new SQLChatHistory($this->threadId, $this->pdo);
        $messages = $newHistory->getMessages();

        $this->assertCount(3, $messages);
        $this->assertInstanceOf(UserMessage::class, $messages[0]);
        $this->assertInstanceOf(ToolCallMessage::class, $messages[1]);
        $this->assertInstanceOf(ToolResultMessage::class, $messages[2]);

        $toolCallMessage = $messages[1];
        $this->assertCount(1, $toolCallMessage->getTools());
        $this->assertEquals('test_tool', $toolCallMessage->getTools()[0]->getName());
    }

    public function test_persists_content_blocks(): void
    {
        $message = new UserMessage([
            new TextContent('First text block'),
            new TextContent('Second text block'),
        ]);

        $this->history->addMessage($message);

        // Load from database
        $newHistory = new SQLChatHistory($this->threadId, $this->pdo);
        $messages = $newHistory->getMessages();

        $this->assertCount(1, $messages);
        $contentBlocks = $messages[0]->getContentBlocks();
        $this->assertCount(2, $contentBlocks);
        $this->assertInstanceOf(TextContent::class, $contentBlocks[0]);
        $this->assertInstanceOf(TextContent::class, $contentBlocks[1]);
        $this->assertEquals('First text block', $contentBlocks[0]->content);
        $this->assertEquals('Second text block', $contentBlocks[1]->content);
    }

    public function test_truncates_history_when_context_window_exceeded(): void
    {
        // Create history with small context window
        $smallHistory = new SQLChatHistory($this->threadId, $this->pdo, contextWindow: 100);

        // Add many messages to exceed context window
        for ($i = 0; $i < 20; $i++) {
            $message = $i % 2 === 0
                ? new UserMessage("User message $i with some text")
                : new AssistantMessage("Assistant message $i with some text");
            $smallHistory->addMessage($message);
        }

        $messages = $smallHistory->getMessages();

        // Should have fewer messages due to truncation
        $this->assertLessThan(20, count($messages));
        $this->assertGreaterThan(0, count($messages));

        // First message should be a user message (valid sequence)
        $this->assertInstanceOf(UserMessage::class, $messages[0]);
    }

    public function test_rejects_invalid_table_name(): void
    {
        $this->expectException(ChatHistoryException::class);
        $this->expectExceptionMessage('Table not allowed');

        new SQLChatHistory('test-thread', $this->pdo, table: 'nonexistent_table');
    }

    public function test_set_messages_updates_database(): void
    {
        $this->history->addMessage(new UserMessage('Message 1'));
        $this->history->addMessage(new AssistantMessage('Message 2'));

        // Verify in database
        $stmt = $this->pdo->prepare("SELECT messages FROM chat_history WHERE thread_id = :thread_id");
        $stmt->execute(['thread_id' => $this->threadId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        $storedMessages = json_decode((string) $result['messages'], true);
        $this->assertCount(2, $storedMessages);
    }

    public function test_multiple_threads_are_isolated(): void
    {
        $thread1 = 'thread-1-' . uniqid();
        $thread2 = 'thread-2-' . uniqid();

        $history1 = new SQLChatHistory($thread1, $this->pdo);
        $history2 = new SQLChatHistory($thread2, $this->pdo);

        $history1->addMessage(new UserMessage('Message in thread 1'));
        $history2->addMessage(new UserMessage('Message in thread 2'));

        $this->assertCount(1, $history1->getMessages());
        $this->assertCount(1, $history2->getMessages());

        // Reload and verify isolation
        $reloaded1 = new SQLChatHistory($thread1, $this->pdo);
        $reloaded2 = new SQLChatHistory($thread2, $this->pdo);

        $this->assertEquals('Message in thread 1', $reloaded1->getMessages()[0]->getContent());
        $this->assertEquals('Message in thread 2', $reloaded2->getMessages()[0]->getContent());

        // Cleanup
        $history1->flushAll();
        $history2->flushAll();
    }
}
