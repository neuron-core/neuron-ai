<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Chat\History;

use NeuronAI\Chat\History\ChatHistory;
use NeuronAI\Chat\History\HistoryTrimmerInterface;
use NeuronAI\Chat\History\InMemoryMessageStore;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Chat\Messages\Usage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Exceptions\ChatHistoryException;
use NeuronAI\Tools\ToolCall;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function array_map;
use function array_pop;
use function array_slice;
use function count;
use function json_encode;

use const JSON_THROW_ON_ERROR;

class ChatHistoryTest extends TestCase
{
    public function test_an_added_message_joins_the_context_and_the_store(): void
    {
        $store = new InMemoryMessageStore();
        $history = new ChatHistory($store, 'thread');
        $message = new UserMessage('Hello!');

        $history->addMessage($message);

        $this->assertSame([$message], $history->getMessages());
        $this->assertSame([$message->getId()], $this->ids($store->loadActive('thread')));
        $this->assertSame('thread', $history->getThreadId());
    }

    public function test_a_history_writes_only_to_its_own_thread(): void
    {
        $store = new InMemoryMessageStore();
        $store->append('other', new UserMessage('Elsewhere'));

        (new ChatHistory($store, 'thread'))->addMessage(new UserMessage('Here'));

        $this->assertCount(1, $store->loadAll('other'));
        $this->assertSame('Here', $store->loadAll('thread')[0]->getContent());
        $this->assertSame('Elsewhere', (new ChatHistory($store, 'other'))->getLastMessage()->getContent());
    }

    public function test_user_message_after_tool_call_is_rejected(): void
    {
        $history = $this->history();
        $history->addMessage(new UserMessage('Delete the file'));
        $history->addMessage(new ToolCallMessage(null, [
            ToolCall::make('delete_file', description: 'd')->setCallId('c1')->setInputs(['path' => '/tmp/x']),
        ]));

        $this->expectException(ChatHistoryException::class);
        $this->expectExceptionMessage('cannot directly follow a ToolCallMessage');

        $history->addMessage(new UserMessage('Another question'));
    }

    public function test_tool_result_after_tool_call_is_allowed(): void
    {
        $tool = ToolCall::make('delete_file', description: 'd')->setCallId('c1')->setInputs(['path' => '/tmp/x']);
        $messages = [
            new UserMessage('Delete the file'),
            new ToolCallMessage(null, [$tool]),
            new ToolResultMessage([(clone $tool)->setResult('File deleted')]),
        ];

        $history = $this->history();
        foreach ($messages as $message) {
            $history->addMessage($message);
        }

        $this->assertSame($messages, $history->getMessages());
    }

    public function test_the_only_user_turn_is_kept_even_over_the_window(): void
    {
        $store = new InMemoryMessageStore();
        $history = new ChatHistory($store, 'thread', 13);
        $history->addMessage(new UserMessage('Hello!'));

        $history->addMessage((new AssistantMessage('Hello!'))->setUsage(new Usage(15, 12)));

        // 27 tokens exceed the window, but trimming may only start at a user message:
        // the backward search finds the first one and nothing is dropped.
        $this->assertCount(2, $history->getMessages());
        $this->assertCount(2, $store->loadActive('thread'));
    }

    /**
     * @return array<string, array{callable(): Message[], string}>
     */
    public static function invalidAppends(): array
    {
        $call = static fn (): ToolCall => new ToolCall('mixed_tool', '123', ['param' => 'value']);

        return [
            'two user messages' => [
                static fn (): array => [new UserMessage('Hello!'), new UserMessage('Hello2!')],
                'Invalid message sequence at position 1: expected role assistant, got user',
            ],
            'user after a tool result' => [
                static fn (): array => [
                    new UserMessage('User message'),
                    (new ToolCallMessage(tools: [$call()]))->setUsage(new Usage(120, 150)),
                    new ToolResultMessage([$call()->setResult('Mixed tool result')]),
                    new UserMessage('User message'),
                ],
                'Invalid message sequence at position 3: expected role assistant, got user',
            ],
            'two assistant messages' => [
                static fn (): array => [
                    new UserMessage('User message'),
                    (new AssistantMessage('Assistant message 1'))->setUsage(new Usage(12, 15)),
                    new AssistantMessage('Assistant message 2'),
                ],
                'Invalid message sequence at position 2: expected role user, got assistant',
            ],
            'an assistant first' => [
                static fn (): array => [new AssistantMessage('Test message')],
                'Invalid message sequence at position 0: expected role user, got assistant',
            ],
        ];
    }

    /**
     * @param callable(): Message[] $sequence
     */
    #[DataProvider('invalidAppends')]
    public function test_an_invalid_append_is_rejected_before_anything_is_stored(callable $sequence, string $error): void
    {
        $store = new InMemoryMessageStore();
        $history = new ChatHistory($store, 'thread');
        $messages = $sequence();
        $rejected = array_pop($messages);
        foreach ($messages as $message) {
            $history->addMessage($message);
        }

        try {
            $history->addMessage($rejected);
            $this->fail('The append should be rejected.');
        } catch (ChatHistoryException $exception) {
            $this->assertSame($error, $exception->getMessage());
        }

        $this->assertSame($this->ids($messages), $this->ids($store->loadAll('thread')));
        $this->assertSame($messages, $history->getMessages());
    }

    public function test_a_loaded_sequence_is_validated_on_the_next_append(): void
    {
        $store = new InMemoryMessageStore();
        $store->append('thread', new UserMessage('Delete it'));
        $store->append('thread', new ToolCallMessage(null, [new ToolCall('delete_file', 'c1')]));
        $history = new ChatHistory($store, 'thread');

        $this->expectException(ChatHistoryException::class);
        $this->expectExceptionMessage('position 2: a UserMessage cannot directly follow a ToolCallMessage');

        $history->addMessage(new UserMessage('Never mind'));
    }

    public function test_tool_rounds_are_kept_in_order(): void
    {
        $first = ToolCall::make('tool_1', description: 'First tool')->setInputs(['param1' => 'value1'])->setCallId('call_1');
        $second = ToolCall::make('tool_2', description: 'Second tool')->setInputs(['param2' => 'value2'])->setCallId('call_2');
        $messages = [
            new UserMessage('Test message'),
            new ToolCallMessage(tools: [$first]),
            new ToolResultMessage([(clone $first)->setResult('First tool result')]),
            new ToolCallMessage(tools: [$second]),
            new ToolResultMessage([(clone $second)->setResult('Second tool result')]),
        ];
        $history = $this->history(1000);

        foreach ($messages as $message) {
            $history->addMessage($message);
        }

        $this->assertSame($messages, $history->getMessages());
    }

    public function test_regular_messages_are_removed_when_context_window_exceeded(): void
    {
        $history = $this->history(1000);

        // AI providers report inputTokens as cumulative context, so the last checkpoint
        // (1000 + 150) is the total: the overflow of 150 is covered by the first checkpoint
        // (200 + 150), so the first turn is trimmed.
        for ($i = 1; $i <= 10; $i++) {
            $history->addMessage($i % 2 === 0
                ? (new AssistantMessage("Message $i - Lorem ipsum dolor sit amet, consectetur adipiscing elit."))->setUsage(new Usage(100 * $i, 150))
                : new UserMessage("Message $i - Lorem ipsum dolor sit amet, consectetur adipiscing elit."));
        }

        $messages = $history->getMessages();
        $this->assertCount(8, $messages);
        $this->assertSame('Message 3 - Lorem ipsum dolor sit amet, consectetur adipiscing elit.', $messages[0]->getContent());
        $this->assertSame(800, $history->calculateTotalUsage());
    }

    public function test_find_trim_point_progressively_exceeds_context_window(): void
    {
        $history = $this->history(500);
        $turns = [];

        // Cumulative checkpoints: 200 and 400 fit in the window, 600 overflows it by 100.
        foreach ([150, 350, 550] as $index => $inputTokens) {
            $turn = [
                new UserMessage('User message ' . ($index + 1)),
                (new AssistantMessage('Assistant message ' . ($index + 1)))->setUsage(new Usage($inputTokens, 50)),
            ];
            foreach ($turn as $message) {
                $history->addMessage($message);
            }
            $turns = [...$turns, ...$turn];
        }

        // The first checkpoint covering the overflow is the first turn (200 tokens).
        $this->assertSame($this->ids(array_slice($turns, 2)), $this->ids($history->getMessages()));
        $this->assertSame(400, $history->calculateTotalUsage());
    }

    public function test_find_trim_point_preserves_tool_call_result_pairs(): void
    {
        $history = $this->history(300);
        $search = ToolCall::make('search_tool', description: 'Search for information')->setInputs(['query' => 'test query 1'])->setCallId('call_1');
        $weather = ToolCall::make('weather_tool', description: 'Get weather info')->setInputs(['location' => 'London'])->setCallId('call_2');

        $messages = [
            new UserMessage('What is the weather?'),
            (new ToolCallMessage(tools: [$search]))->setUsage(new Usage(50, 30)),
            new ToolResultMessage([(clone $search)->setResult('Search result 1')]),
            (new AssistantMessage('Based on the search...'))->setUsage(new Usage(120, 40)),
            new UserMessage('Tell me more'),
            (new ToolCallMessage(tools: [$weather]))->setUsage(new Usage(200, 35)),
            new ToolResultMessage([(clone $weather)->setResult('Sunny, 25°C')]),
            (new AssistantMessage('The weather in London...'))->setUsage(new Usage(350, 50)),
        ];
        foreach ($messages as $message) {
            $history->addMessage($message);
        }

        // The 400 tokens overflow by 100: the first turn (160 tokens) goes as a whole,
        // its tool call and result together.
        $this->assertSame($this->ids(array_slice($messages, 4)), $this->ids($history->getMessages()));
        $this->assertSame(240, $history->calculateTotalUsage());
    }

    public function test_loading_is_deferred_to_first_use(): void
    {
        $store = new InMemoryMessageStore();
        $history = new ChatHistory($store, 'thread');

        // Stored after construction: an eager load would miss it.
        $store->append('thread', new UserMessage('Stored later'));

        $this->assertSame('Stored later', $history->getMessages()[0]->getContent());
    }

    public function test_a_failed_load_is_retried_on_the_next_access(): void
    {
        $store = new class () extends InMemoryMessageStore {
            public bool $failing = true;

            public function loadActive(string $threadId): array
            {
                if ($this->failing) {
                    $this->failing = false;
                    throw new RuntimeException('Connection lost');
                }

                return parent::loadActive($threadId);
            }
        };
        $store->append('thread', new UserMessage('Hello'));
        $history = new ChatHistory($store, 'thread');

        try {
            $history->getMessages();
            $this->fail('The first load should fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Connection lost', $exception->getMessage());
        }

        $this->assertCount(1, $history->getMessages());
    }

    public function test_a_failed_write_leaves_the_context_unchanged_and_can_be_replayed(): void
    {
        $store = new class () extends InMemoryMessageStore {
            public bool $failing = false;

            public function append(string $threadId, Message $message): void
            {
                if ($this->failing) {
                    $this->failing = false;
                    throw new RuntimeException('Connection lost');
                }

                parent::append($threadId, $message);
            }
        };
        $history = new ChatHistory($store, 'thread');
        $question = new UserMessage('Hello');
        $history->addMessage($question);
        $answer = new AssistantMessage('Hi');

        $store->failing = true;
        try {
            $history->addMessage($answer);
            $this->fail('The write should fail.');
        } catch (RuntimeException) {
        }

        $this->assertSame([$question], $history->getMessages());

        $history->addMessage($answer);
        $this->assertSame([$question, $answer], $history->getMessages());
        $this->assertSame($this->ids([$question, $answer]), $this->ids($store->loadAll('thread')));
    }

    public function test_a_message_already_in_the_context_is_skipped(): void
    {
        $store = new InMemoryMessageStore();
        $history = new ChatHistory($store, 'thread');
        $message = new UserMessage('Hello');

        $history->addMessage($message);
        $history->addMessage($message);

        $this->assertCount(1, $history->getMessages());
        $this->assertCount(1, $store->loadAll('thread'));
    }

    public function test_a_replayed_message_is_recognized_by_its_identity(): void
    {
        $store = new InMemoryMessageStore();
        $history = new ChatHistory($store, 'thread');
        $message = new UserMessage('Hello');
        $history->addMessage($message);

        // The same message rebuilt from storage is another object with the same identity.
        $history->addMessage((new UserMessage('Hello'))->setId($message->getId()));

        $this->assertSame([$message], $history->getMessages());
        $this->assertCount(1, $store->loadAll('thread'));
    }

    public function test_trimmed_messages_are_archived_in_the_store(): void
    {
        $store = new InMemoryMessageStore();
        $history = new ChatHistory($store, 'thread', 1000);

        for ($i = 1; $i <= 10; $i++) {
            $history->addMessage($i % 2 === 0
                ? (new AssistantMessage("Message {$i}"))->setUsage(new Usage(100 * $i, 150))
                : new UserMessage("Message {$i}"));
        }

        $this->assertCount(8, $history->getMessages());
        $this->assertSame($this->ids($history->getMessages()), $this->ids($store->loadActive('thread')));
        $this->assertCount(10, $store->loadAll('thread'));
    }

    public function test_the_store_archives_exactly_what_the_trimmer_dropped(): void
    {
        $trimmer = new class () implements HistoryTrimmerInterface {
            public ?int $contextWindow = null;

            public function getTotalTokens(): int
            {
                return 0;
            }

            public function trim(array $messages, int $contextWindow): array
            {
                $this->contextWindow = $contextWindow;

                // Keep the last two messages.
                return array_slice($messages, -2);
            }
        };
        $store = new InMemoryMessageStore();
        $history = new ChatHistory($store, 'thread', 1234, $trimmer);
        $messages = [new UserMessage('1'), new AssistantMessage('2'), new UserMessage('3'), new AssistantMessage('4')];

        foreach ($messages as $message) {
            $history->addMessage($message);
        }

        $this->assertSame(1234, $trimmer->contextWindow);
        $this->assertSame(array_slice($messages, 2), $history->getMessages());
        $this->assertSame($this->ids(array_slice($messages, 2)), $this->ids($store->loadActive('thread')));
        $this->assertSame($this->ids($messages), $this->ids($store->loadAll('thread')));
    }

    public function test_usage_is_measured_on_a_freshly_loaded_history(): void
    {
        $store = new InMemoryMessageStore();
        $writer = new ChatHistory($store, 'thread');
        $writer->addMessage(new UserMessage('Hello'));
        $writer->addMessage((new AssistantMessage('Hi'))->setUsage(new Usage(120, 30)));

        $reader = new ChatHistory($store, 'thread');

        $this->assertSame(150, $reader->calculateTotalUsage());
        $this->assertSame($writer->calculateTotalUsage(), $reader->calculateTotalUsage());
    }

    public function test_measuring_the_usage_never_trims(): void
    {
        // Stored by a history with a larger window: two turns, 1000 tokens.
        $store = new InMemoryMessageStore();
        $store->append('thread', new UserMessage('Hello'));
        $store->append('thread', (new AssistantMessage('Hi'))->setUsage(new Usage(400, 100)));
        $store->append('thread', new UserMessage('Again'));
        $store->append('thread', (new AssistantMessage('Hi again'))->setUsage(new Usage(900, 100)));
        $history = new ChatHistory($store, 'thread', 10);

        $this->assertSame(1000, $history->calculateTotalUsage());
        $this->assertCount(4, $history->getMessages());
        $this->assertSame(900, $history->getLastMessage()->getUsage()?->inputTokens);
        $this->assertCount(4, $store->loadActive('thread'));
    }

    public function test_an_empty_history_measures_zero(): void
    {
        $this->assertSame(0, $this->history()->calculateTotalUsage());
    }

    public function test_the_last_message_is_the_latest_in_the_context(): void
    {
        $history = $this->history();
        $history->addMessage(new UserMessage('Hello'));
        $answer = new AssistantMessage('Hi');
        $history->addMessage($answer);

        $this->assertSame($answer, $history->getLastMessage());
    }

    public function test_an_empty_history_has_no_last_message(): void
    {
        $this->expectException(ChatHistoryException::class);
        $this->expectExceptionMessage('No messages in the chat history.');

        $this->history()->getLastMessage();
    }

    public function test_the_history_serializes_as_its_active_messages(): void
    {
        $history = $this->history();
        $question = new UserMessage('Hello');
        $history->addMessage($question);

        $this->assertSame([$question], $history->jsonSerialize());
        $this->assertSame(json_encode([$question], JSON_THROW_ON_ERROR), json_encode($history, JSON_THROW_ON_ERROR));
    }

    public function test_flush_all_removes_the_thread_from_the_store(): void
    {
        $store = new InMemoryMessageStore();
        $history = new ChatHistory($store, 'thread');
        $history->addMessage(new UserMessage('Hello'));

        $history->flushAll();

        $this->assertSame([], $history->getMessages());
        $this->assertSame([], $store->loadAll('thread'));
    }

    public function test_flush_all_erases_the_archived_messages_too(): void
    {
        $store = new InMemoryMessageStore();
        $history = new ChatHistory($store, 'thread', 100);
        for ($i = 1; $i <= 6; $i++) {
            $history->addMessage($i % 2 === 0
                ? (new AssistantMessage("Answer {$i}"))->setUsage(new Usage(60 * $i, 10))
                : new UserMessage("Question {$i}"));
        }
        $this->assertGreaterThan(count($store->loadActive('thread')), count($store->loadAll('thread')));

        $history->flushAll();

        $this->assertSame([], $store->loadAll('thread'));
        $history->addMessage(new UserMessage('A fresh start'));
        $this->assertCount(1, $store->loadAll('thread'));
    }

    protected function history(int $contextWindow = 50000): ChatHistory
    {
        return new ChatHistory(new InMemoryMessageStore(), 'thread', $contextWindow);
    }

    /**
     * @param Message[] $messages
     * @return string[]
     */
    protected function ids(array $messages): array
    {
        return array_map(fn (Message $message): string => $message->getId(), $messages);
    }
}
