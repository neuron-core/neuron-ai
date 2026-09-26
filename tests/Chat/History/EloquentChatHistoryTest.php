<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Chat\History;

use NeuronAI\Tests\Chat\History\Stub\ChatMessage;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
use NeuronAI\Agent\Agent;
use NeuronAI\Chat\History\ChatHistory;
use NeuronAI\Chat\History\EloquentMessageStore;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Chat\Messages\Usage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Tools\ToolCall;
use PHPUnit\Framework\TestCase;

use function array_map;
use function count;
use function uniqid;

class EloquentChatHistoryTest extends TestCase
{
    protected ChatHistory $history;
    protected string $threadId;

    public function setUp(): void
    {
        // Set up an in-memory SQLite database for testing
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
            $table->string('message_id', 64);
            $table->string('role');
            $table->json('content')->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->index('thread_id');
            $table->unique(['thread_id', 'message_id']);
        });

        $this->threadId = uniqid('test-thread-');
        $this->history = new ChatHistory(new EloquentMessageStore(ChatMessage::class), $this->threadId);
    }

    protected function tearDown(): void
    {
        Capsule::schema()->dropIfExists('chat_messages');
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
        $this->assertEquals([["type" => "text","content" => "Hello from Eloquent!","meta" => []]], $record->content);
    }

    public function test_loads_existing_messages_from_database(): void
    {
        // Add messages to the thread
        $this->history->addMessage(new UserMessage('First message'));
        $this->history->addMessage(new AssistantMessage('Second message'));

        // Create a new instance with the same thread_id
        $newHistory = new ChatHistory(new EloquentMessageStore(ChatMessage::class), $this->threadId);

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
        $tool = ToolCall::make('test_tool', description: 'A test tool')
            ->setInputs(['param' => 'value'])
            ->setCallId('call_123');

        $toolWithResult = ToolCall::make('test_tool', description: 'A test tool')
            ->setInputs(['param' => 'value'])
            ->setCallId('call_123')
            ->setResult('Tool result');

        $this->history->addMessage(new UserMessage('Use the tool'));
        $this->history->addMessage(new ToolCallMessage(null, [$tool]));
        $this->history->addMessage(new ToolResultMessage([$toolWithResult]));

        // Create new instance and verify tool messages are loaded correctly
        $newHistory = new ChatHistory(new EloquentMessageStore(ChatMessage::class), $this->threadId);
        $messages = $newHistory->getMessages();

        $this->assertCount(3, $messages);
        $this->assertInstanceOf(UserMessage::class, $messages[0]);
        $this->assertInstanceOf(ToolCallMessage::class, $messages[1]);
        $this->assertInstanceOf(ToolResultMessage::class, $messages[2]);

        $toolCallMessage = $messages[1];
        $this->assertCount(1, $toolCallMessage->getToolCalls());
        $this->assertEquals('test_tool', $toolCallMessage->getToolCalls()[0]->getName());
    }

    public function test_truncates_history_when_context_window_exceeded(): void
    {
        $smallHistory = new ChatHistory(new EloquentMessageStore(ChatMessage::class), $this->threadId, 100);

        $this->addMessagesBeyondContextWindow($smallHistory);

        // Every turn overflows the window, so only the latest one stays in the context.
        $messages = $smallHistory->getMessages();
        $this->assertSame(['User message 19 with some text', 'Assistant message 20 with some text'], array_map(
            fn (Message $message): ?string => $message->getContent(),
            $messages
        ));

        // The trimmed messages are archived in the database, not deleted
        $rows = ChatMessage::query()->where('thread_id', $this->threadId);
        $this->assertSame(
            array_map(fn (Message $message): string => $message->getId(), $messages),
            (clone $rows)->whereNull('archived_at')->orderBy('id')->pluck('message_id')->all()
        );
        $this->assertSame(18, (clone $rows)->whereNotNull('archived_at')->count());
    }

    public function test_loads_only_unarchived_messages(): void
    {
        $smallHistory = new ChatHistory(new EloquentMessageStore(ChatMessage::class), $this->threadId, 100);

        $this->addMessagesBeyondContextWindow($smallHistory);

        $active = $smallHistory->getMessages();

        $reloaded = new ChatHistory(new EloquentMessageStore(ChatMessage::class), $this->threadId);
        $messages = $reloaded->getMessages();

        $this->assertCount(count($active), $messages);
        $this->assertEquals($active[0]->getContent(), $messages[0]->getContent());
    }

    /**
     * Twenty alternating messages whose usage grows past a 100 tokens context window.
     */
    protected function addMessagesBeyondContextWindow(ChatHistory $history): void
    {
        // Start with UserMessage (i=1 is odd) to create valid sequence
        for ($i = 1; $i <= 20; $i++) {
            $message = $i % 2 === 1
                ? new UserMessage("User message $i with some text")
                : (new AssistantMessage("Assistant message $i with some text"))->setUsage(new Usage(100 * $i, 150));
            $history->addMessage($message);
        }
    }

    public function test_multiple_threads_are_isolated(): void
    {
        $thread1 = 'thread-1-' . uniqid();
        $thread2 = 'thread-2-' . uniqid();

        $history1 = new ChatHistory(new EloquentMessageStore(ChatMessage::class), $thread1);
        $history2 = new ChatHistory(new EloquentMessageStore(ChatMessage::class), $thread2);

        $history1->addMessage(new UserMessage('Message in thread 1'));
        $history2->addMessage(new UserMessage('Message in thread 2'));

        $this->assertCount(1, $history1->getMessages());
        $this->assertCount(1, $history2->getMessages());

        // Reload and verify isolation
        $reloaded1 = new ChatHistory(new EloquentMessageStore(ChatMessage::class), $thread1);
        $reloaded2 = new ChatHistory(new EloquentMessageStore(ChatMessage::class), $thread2);

        $this->assertEquals('Message in thread 1', $reloaded1->getMessages()[0]->getContent());
        $this->assertEquals('Message in thread 2', $reloaded2->getMessages()[0]->getContent());

        // Verify database isolation
        $this->assertEquals(1, ChatMessage::query()->where('thread_id', $thread1)->count());
        $this->assertEquals(1, ChatMessage::query()->where('thread_id', $thread2)->count());
    }

    public function test_rows_follow_the_insertion_order_of_the_key(): void
    {
        $messages = [new UserMessage('Message 1'), new AssistantMessage('Message 2'), new UserMessage('Message 3')];
        foreach ($messages as $message) {
            $this->history->addMessage($message);
        }

        $this->assertSame(
            array_map(fn (Message $message): string => $message->getId(), $messages),
            ChatMessage::query()->where('thread_id', $this->threadId)->orderBy('id')->pluck('message_id')->all()
        );
    }

    public function test_an_empty_thread_id_is_a_thread_of_its_own(): void
    {
        $emptyThreadHistory = new ChatHistory(new EloquentMessageStore(ChatMessage::class), '');
        $emptyThreadHistory->addMessage(new UserMessage('Test'));
        $this->history->addMessage(new UserMessage('Other'));

        $reloaded = new ChatHistory(new EloquentMessageStore(ChatMessage::class), '');
        $this->assertCount(1, $reloaded->getMessages());
        $this->assertSame('Test', $reloaded->getMessages()[0]->getContent());
    }

    public function test_the_meta_column_holds_no_role_or_content(): void
    {
        $message = (new AssistantMessage('Hi'))->setUsage(new Usage(10, 5))->setStopReason('end_turn');
        $this->history->addMessage(new UserMessage('Hello'));
        $this->history->addMessage($message);

        $record = ChatMessage::query()->where('message_id', $message->getId())->firstOrFail();

        $this->assertSame('assistant', $record->role);
        $this->assertSame([
            '__id' => $message->getId(),
            'usage' => ['input_tokens' => 10, 'output_tokens' => 5, 'cached_input_tokens' => 0, 'reasoning_tokens' => 0],
            '__meta' => ['stop_reason' => 'end_turn'],
        ], $record->meta);
    }

    public function test_serializes_message_meta_correctly(): void
    {
        $message = new UserMessage('Test message');
        $message->addMetadata('custom_key', 'custom_value');

        $this->history->addMessage($message);

        // Load in new instance
        $newHistory = new ChatHistory(new EloquentMessageStore(ChatMessage::class), $this->threadId);
        $loadedMessage = $newHistory->getMessages()[0];

        $this->assertEquals('custom_value', $loadedMessage->getMetadata('custom_key'));
    }

    public function test_with_agent(): void
    {
        $agent = Agent::make()->setAiProvider(
            new FakeAIProvider(new AssistantMessage('Hello!'))
        )->setMessageStore(new EloquentMessageStore(ChatMessage::class));

        $response = $agent->chat(new UserMessage('Hello'))->getMessage();
        $this->assertEquals('Hello!', $response->getContent());

        $records = ChatMessage::query()->orderBy('id')->get();
        $this->assertSame(['user', 'assistant'], $records->pluck('role')->all());
        $this->assertSame($records[0]->thread_id, $records[1]->thread_id);
        $this->assertSame($response->getId(), $records[1]->message_id);
    }
}
