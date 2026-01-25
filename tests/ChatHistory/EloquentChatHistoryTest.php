<?php

declare(strict_types=1);

namespace NeuronAI\Tests\ChatHistory;

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use NeuronAI\Chat\History\ChatHistoryInterface;
use NeuronAI\Chat\History\EloquentChatHistory;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Chat\Messages\Usage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Tests\Traits\CheckOpenPort;
use NeuronAI\Tools\Tool;
use PHPUnit\Framework\TestCase;

use function count;
use function uniqid;

class EloquentChatHistoryTest extends TestCase
{
    use CheckOpenPort;

    protected EloquentChatHistory $history;
    protected string $threadId;

    public function setUp(): void
    {
        if (!$this->isPortOpen('127.0.0.1', 3306)) {
            $this->markTestSkipped("MySQL not available on port 3306. Skipping test.");
        }

        // Set up in-memory SQLite database for testing
        $capsule = new Capsule();
        $capsule->addConnection([
            'driver' => 'sqlite',
            'database' => ':memory:',
        ]);
        $capsule->setAsGlobal();
        $capsule->bootEloquent();

        // Create the chat_messages table
        Capsule::schema()->create('chat_messages', function (Blueprint $table): void {
            $table->id();
            $table->string('thread_id');
            $table->string('role');
            $table->text('content')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index('thread_id');
        });

        $this->threadId = uniqid('test-thread-');
        $this->history = new EloquentChatHistory($this->threadId, ChatMessage::class);
    }

    protected function tearDown(): void
    {
        Capsule::schema()->dropIfExists('chat_messages');
    }

    public function test_creates_chat_history_instance(): void
    {
        $this->assertInstanceOf(ChatHistoryInterface::class, $this->history);
    }

    public function test_starts_with_empty_history(): void
    {
        $messages = $this->history->getMessages();
        $this->assertCount(0, $messages);
    }

    public function test_adds_message_to_database(): void
    {
        $message = new UserMessage('Hello from Eloquent!');
        $this->history->addMessage($message);

        // Verify in database
        $count = ChatMessage::query()->where('thread_id', $this->threadId)->count();
        $this->assertEquals(1, $count);

        // Verify message content
        $record = ChatMessage::query()->where('thread_id', $this->threadId)->first();
        $this->assertEquals('user', $record->role);
        $this->assertEquals('[{"type":"text","content":"Hello from Eloquent!"}]', $record->content);
    }

    public function test_loads_existing_messages_from_database(): void
    {
        // Add messages to the thread
        $this->history->addMessage(new UserMessage('First message'));
        $this->history->addMessage(new AssistantMessage('Second message'));

        // Create a new instance with the same thread_id
        $newHistory = new EloquentChatHistory($this->threadId, ChatMessage::class);

        // Should load existing messages
        $messages = $newHistory->getMessages();
        $this->assertCount(2, $messages);
        $this->assertInstanceOf(UserMessage::class, $messages[0]);
        $this->assertInstanceOf(AssistantMessage::class, $messages[1]);
        $this->assertEquals('First message', $messages[0]->getContent());
        $this->assertEquals('Second message', $messages[1]->getContent());
    }

    public function test_persists_multiple_messages(): void
    {
        $this->history->addMessage(new UserMessage('Message 1'));
        $this->history->addMessage(new AssistantMessage('Message 2'));
        $this->history->addMessage(new UserMessage('Message 3'));

        $count = ChatMessage::query()->where('thread_id', $this->threadId)->count();
        $this->assertEquals(3, $count);

        $messages = $this->history->getMessages();
        $this->assertCount(3, $messages);
    }

    public function test_clear_removes_all_messages_from_database(): void
    {
        $this->history->addMessage(new UserMessage('Test message'));
        $this->assertEquals(1, ChatMessage::query()->where('thread_id', $this->threadId)->count());

        $this->history->flushAll();

        // Verify messages are removed from database
        $this->assertEquals(0, ChatMessage::query()->where('thread_id', $this->threadId)->count());

        // Verify history is empty
        $this->assertCount(0, $this->history->getMessages());
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
        $this->history->addMessage(new ToolCallMessage(null, [$tool]));
        $this->history->addMessage(new ToolResultMessage([$toolWithResult]));

        // Create new instance and verify tool messages are loaded correctly
        $newHistory = new EloquentChatHistory($this->threadId, ChatMessage::class);
        $messages = $newHistory->getMessages();

        $this->assertCount(3, $messages);
        $this->assertInstanceOf(UserMessage::class, $messages[0]);
        $this->assertInstanceOf(ToolCallMessage::class, $messages[1]);
        $this->assertInstanceOf(ToolResultMessage::class, $messages[2]);

        $toolCallMessage = $messages[1];
        $this->assertCount(1, $toolCallMessage->getTools());
        $this->assertEquals('test_tool', $toolCallMessage->getTools()[0]->getName());
    }

    public function test_truncates_history_when_context_window_exceeded(): void
    {
        // Create history with small context window
        $smallHistory = new EloquentChatHistory($this->threadId, ChatMessage::class, contextWindow: 100);

        // Add many messages to exceed context window
        for ($i = 1; $i <= 20; $i++) {
            $message = $i % 2 === 0
                ? new UserMessage("User message $i with some text")
                : (new AssistantMessage("Assistant message $i with some text"))->setUsage(new Usage(100 * $i, 150));
            $smallHistory->addMessage($message);
        }

        $messages = $smallHistory->getMessages();

        // Should have fewer messages due to truncation
        $this->assertLessThan(20, count($messages));
        $this->assertGreaterThan(0, count($messages));

        // First message should be a user message (valid sequence)
        $this->assertInstanceOf(UserMessage::class, $messages[0]);

        // Note: The database may have more messages than memory during the addition process
        // This test mainly verifies that truncation happens in memory
        // Database synchronization behavior may vary based on implementation
    }

    public function test_multiple_threads_are_isolated(): void
    {
        $thread1 = 'thread-1-' . uniqid();
        $thread2 = 'thread-2-' . uniqid();

        $history1 = new EloquentChatHistory($thread1, ChatMessage::class);
        $history2 = new EloquentChatHistory($thread2, ChatMessage::class);

        $history1->addMessage(new UserMessage('Message in thread 1'));
        $history2->addMessage(new UserMessage('Message in thread 2'));

        $this->assertCount(1, $history1->getMessages());
        $this->assertCount(1, $history2->getMessages());

        // Reload and verify isolation
        $reloaded1 = new EloquentChatHistory($thread1, ChatMessage::class);
        $reloaded2 = new EloquentChatHistory($thread2, ChatMessage::class);

        $this->assertEquals('Message in thread 1', $reloaded1->getMessages()[0]->getContent());
        $this->assertEquals('Message in thread 2', $reloaded2->getMessages()[0]->getContent());

        // Verify database isolation
        $this->assertEquals(1, ChatMessage::query()->where('thread_id', $thread1)->count());
        $this->assertEquals(1, ChatMessage::query()->where('thread_id', $thread2)->count());
    }

    public function test_set_messages_maintains_database_consistency(): void
    {
        // Add initial messages
        $this->history->addMessage(new UserMessage('Message 1'));
        $this->history->addMessage(new AssistantMessage('Message 2'));

        $this->assertEquals(2, ChatMessage::query()->where('thread_id', $this->threadId)->count());

        // The setMessages method is used internally by addMessage
        // Verify that in-memory and database are in sync
        $messages = $this->history->getMessages();
        $dbRecords = ChatMessage::query()->where('thread_id', $this->threadId)->orderBy('id')->count();

        $this->assertEquals(count($messages), $dbRecords);
    }

    public function test_handles_empty_thread_id(): void
    {
        $emptyThreadHistory = new EloquentChatHistory('', ChatMessage::class);
        $emptyThreadHistory->addMessage(new UserMessage('Test'));

        // Should still work with empty thread_id
        $this->assertCount(1, $emptyThreadHistory->getMessages());
    }

    public function test_serializes_message_meta_correctly(): void
    {
        $message = new UserMessage('Test message');
        $message->addMetadata('custom_key', 'custom_value');

        $this->history->addMessage($message);

        // Load in new instance
        $newHistory = new EloquentChatHistory($this->threadId, ChatMessage::class);
        $loadedMessage = $newHistory->getMessages()[0];

        $this->assertEquals('custom_value', $loadedMessage->getMetadata('custom_key'));
    }
}

/**
 * Mock Eloquent Model for testing
 *
 * @property string $thread_id
 * @property string $role
 * @property string $content
 * @property array $meta
 */
class ChatMessage extends Model
{
    protected $table = 'chat_messages';
    protected $fillable = ['thread_id', 'role', 'content', 'meta'];
    protected $casts = [
        'meta' => 'array',
    ];
}
