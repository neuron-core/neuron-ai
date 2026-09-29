<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Chat\History;

use NeuronAI\Chat\Enums\MessageRole;
use NeuronAI\Chat\History\HistoryTrimmer;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Chat\Messages\Usage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Exceptions\ChatHistoryException;
use NeuronAI\Tests\Chat\History\Stub\Conversation;
use NeuronAI\Tools\ToolCall;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function array_map;
use function array_slice;
use function array_sum;
use function str_repeat;

use const PHP_INT_MAX;

class HistoryTrimmerTest extends TestCase
{
    public function test_an_empty_history_has_no_tokens(): void
    {
        $trimmer = new HistoryTrimmer();
        $trimmer->trim($this->conversation(), 1000);

        $this->assertSame([], $trimmer->trim([], 1000));
        $this->assertSame(0, $trimmer->getTotalTokens());
    }

    public function test_the_total_is_the_last_checkpoint_plus_the_estimated_tail(): void
    {
        $messages = [
            new UserMessage('Hello'),
            (new AssistantMessage('Hi'))->setUsage(new Usage(100, 20)),
            new UserMessage('Hello'),
        ];
        $trimmer = new HistoryTrimmer();

        $this->assertSame($messages, $trimmer->trim($messages, 1000));
        // 120 reported by the provider + 12 estimated for the trailing user message
        $this->assertSame(132, $trimmer->getTotalTokens());
    }

    public function test_a_history_exactly_at_the_window_is_kept_whole(): void
    {
        $messages = $this->conversation();
        $trimmer = new HistoryTrimmer();

        $this->assertSame($messages, $trimmer->trim($messages, 300));
        $this->assertSame(300, $trimmer->getTotalTokens());
    }

    public function test_a_history_up_to_five_percent_over_the_window_keeps_its_oldest_turn(): void
    {
        $messages = $this->conversation();
        $trimmer = new HistoryTrimmer();

        // Dropping the first question alone would fit, but a history cannot open with an answer
        $this->assertSame($messages, $trimmer->trim($messages, 286));
        $this->assertSame(300, $trimmer->getTotalTokens());
    }

    public function test_beyond_five_percent_over_the_window_the_oldest_turn_is_dropped(): void
    {
        $messages = $this->conversation();
        $trimmer = new HistoryTrimmer();

        $trimmed = $trimmer->trim($messages, 285);

        $this->assertSame($this->ids(array_slice($messages, 2)), $this->ids($trimmed));
        // The instructions and tools stay: 50 + the second turn's 130
        $this->assertSame(180, $trimmer->getTotalTokens());
    }

    public function test_a_cut_is_priced_by_the_messages_it_drops_not_by_the_instructions(): void
    {
        // 10,000 tokens of instructions and tools ride with every request and are never dropped
        $conversation = new Conversation(10000);
        for ($turn = 1; $turn <= 20; $turn++) {
            $conversation->user(1000)->answer(1000);
        }
        $messages = $conversation->user(11000, 'A long document')->answer(1000)->messages();
        $trimmer = new HistoryTrimmer();

        // 62,000 for a 50,000 window: six turns of messages must go, not one turn plus the instructions
        $kept = $trimmer->trim($messages, 50000);

        $this->assertCount(30, $kept);
        $this->assertSame(50000, $trimmer->getTotalTokens());
        $this->assertSame(50000, 10000 + array_sum(array_map(Conversation::tokens(...), $kept)));
        $this->assertSame(49000, $kept[29]->getUsage()?->inputTokens);
    }

    public function test_a_cut_that_lands_exactly_on_the_window_keeps_the_next_turn(): void
    {
        $messages = $this->conversation();
        $trimmer = new HistoryTrimmer();

        // Dropping the first turn (120 of 300 tokens) leaves exactly the 180-token window.
        $trimmed = $trimmer->trim($messages, 180);

        $this->assertSame($this->ids(array_slice($messages, 2)), $this->ids($trimmed));
        $this->assertSame(180, $trimmer->getTotalTokens());
        $this->assertSame(150, $trimmed[1]->getUsage()?->inputTokens);
    }

    public function test_a_reused_trimmer_measures_another_conversation_of_the_same_length_afresh(): void
    {
        $trimmer = new HistoryTrimmer();
        $first = [new UserMessage('Hello'), (new AssistantMessage('Hi'))->setUsage(new Usage(100, 10))];
        $second = [new UserMessage('Hello'), (new AssistantMessage('Hi'))->setUsage(new Usage(200, 20))];

        $trimmer->trim($first, 1000);
        $trimmer->trim($second, 1000);

        $this->assertSame(220, $trimmer->getTotalTokens());
    }

    public function test_usage_set_after_a_measurement_counts_at_the_next_one(): void
    {
        $trimmer = new HistoryTrimmer();
        $messages = [new UserMessage('Hello'), new AssistantMessage('Hello')];
        $trimmer->trim($messages, PHP_INT_MAX);

        $messages[1]->setUsage(new Usage(5000, 10));
        $trimmer->trim($messages, PHP_INT_MAX);

        $this->assertSame(5010, $trimmer->getTotalTokens());
    }

    public function test_a_reused_trimmer_never_measures_with_a_freed_conversations_checkpoints(): void
    {
        $trimmer = new HistoryTrimmer();
        $user = new UserMessage('Hello');

        // The measured answer is freed right after: PHP may hand its object hash to the next message
        $trimmer->trim([$user, (new AssistantMessage('Hello'))->setUsage(new Usage(1000, 10))], PHP_INT_MAX);
        $unmeasured = [$user, new AssistantMessage('Hello')];
        $trimmer->trim($unmeasured, PHP_INT_MAX);

        $this->assertSame(25, $trimmer->getTotalTokens());
    }

    public function test_the_kept_checkpoints_are_rebased_on_the_trimmed_context(): void
    {
        $trimmer = new HistoryTrimmer();

        $trimmed = $trimmer->trim($this->conversation(), 285);

        // 270 minus the dropped turn's 120: the instructions and tools (50) stay with the second question (100)
        $usage = $trimmed[1]->getUsage();
        $this->assertInstanceOf(Usage::class, $usage);
        $this->assertSame(150, $usage->inputTokens);
        $this->assertSame(30, $usage->outputTokens);
    }

    public function test_a_rebased_checkpoint_keeps_its_cache_and_reasoning_counts(): void
    {
        $messages = (new Conversation(50))->user(100)->answer(50)->user(100)->messages();
        $messages[] = (new AssistantMessage('a2'))->setUsage(new Usage(300, 50, 200, 20));

        $trimmed = (new HistoryTrimmer())->trim($messages, 300);

        $this->assertSame(
            ['input_tokens' => 150, 'output_tokens' => 50, 'cached_input_tokens' => 200, 'reasoning_tokens' => 20],
            $trimmed[1]->getUsage()?->jsonSerialize()
        );
    }

    public function test_a_rebased_checkpoint_never_reports_negative_input_tokens(): void
    {
        // An answer's output tokens can include reasoning the next request never
        // carries (e.g. OpenAI reasoning models), so the dropped part can exceed
        // the input the later answers report.
        $messages = [
            new UserMessage('first'),
            (new AssistantMessage('one'))->setUsage(new Usage(10, 500)),
            new UserMessage('second'),
            (new AssistantMessage('two'))->setUsage(new Usage(400, 200)),
        ];

        $trimmed = (new HistoryTrimmer())->trim($messages, 100);

        $usage = $trimmed[1]->getUsage();
        $this->assertInstanceOf(Usage::class, $usage);
        $this->assertSame(0, $usage->inputTokens);
        $this->assertSame(200, $usage->outputTokens);
    }

    public function test_trimming_an_already_trimmed_history_changes_nothing(): void
    {
        $trimmer = new HistoryTrimmer();
        $trimmed = $trimmer->trim($this->conversation(), 285);

        $this->assertSame($trimmed, $trimmer->trim($trimmed, 285));
        $this->assertSame(180, $trimmer->getTotalTokens());
    }

    public function test_without_usage_the_oldest_messages_are_dropped_by_estimation(): void
    {
        // Each user message is estimated at 12 tokens, each assistant message at 13.
        $messages = [new UserMessage('Hello'), new AssistantMessage('Hello'), new UserMessage('Hello'), new AssistantMessage('Hello')];

        $trimmed = (new HistoryTrimmer())->trim($messages, 30);

        $this->assertSame($this->ids(array_slice($messages, 2)), $this->ids($trimmed));
    }

    public function test_without_usage_tool_call_arguments_count_toward_the_window(): void
    {
        $messages = [
            new UserMessage('first question'),
            new AssistantMessage('first answer'),
            new UserMessage('write the file'),
            new ToolCallMessage(null, [new ToolCall('write_file', 'call-1', ['content' => str_repeat('a', 40000)])]),
            new ToolResultMessage([(new ToolCall('write_file', 'call-1'))->setResult('ok')]),
        ];
        $trimmer = new HistoryTrimmer();

        $trimmed = $trimmer->trim($messages, 5000);

        // The call alone is about 10,000 tokens: the older turn goes, and the latest turn is kept whole.
        $this->assertSame($this->ids(array_slice($messages, 2)), $this->ids($trimmed));
        $this->assertGreaterThan(10000, $trimmer->getTotalTokens());
    }

    public function test_a_tool_call_and_its_result_are_never_split(): void
    {
        $messages = $this->toolRoundConversation();

        // The first checkpoint past the overflow is the tool call: the cut would land on its result.
        $trimmed = (new HistoryTrimmer())->trim($messages, 150);

        $this->assertSame($this->ids(array_slice($messages, 2)), $this->ids($trimmed));
    }

    public function test_a_cut_moved_back_to_its_turn_counts_only_the_dropped_messages(): void
    {
        $messages = $this->toolRoundConversation();
        $trimmer = new HistoryTrimmer();

        $trimmed = $trimmer->trim($messages, 150);

        // Only the first turn (30 tokens) is dropped: 185 - 30, the instructions and tools included.
        $this->assertSame(155, $trimmer->getTotalTokens());
        $this->assertSame(70, $trimmed[1]->getUsage()?->inputTokens);
        $this->assertSame(140, $trimmed[5]->getUsage()?->inputTokens);
    }

    /**
     * @return array<string, array{int, string, int}>
     */
    public static function overflowTolerance(): array
    {
        return [
            'one percent over keeps the turn' => [11, 'second', 101],
            'exactly five percent over keeps the turn' => [15, 'second', 105],
            'beyond five percent cuts at the next turn' => [16, 'third', 46],
        ];
    }

    #[DataProvider('overflowTolerance')]
    public function test_a_turn_is_kept_while_the_overflow_stays_within_five_percent(int $lastAnswer, string $firstKept, int $total): void
    {
        // 10 of instructions and tools, a 30-token first turn and a 60-token tool round
        $messages = (new Conversation(10))
            ->user(20, 'first')->answer(10)
            ->user(20, 'second')->toolCall(10, 'call-1')->toolResult(20, 'call-1')->answer(10)
            ->user(20, 'third')->answer($lastAnswer)
            ->messages();
        $trimmer = new HistoryTrimmer();

        // The smallest cut lands inside the second turn: keeping it costs only the first turn's 30 tokens.
        $trimmed = $trimmer->trim($messages, 100);

        $this->assertStringStartsWith($firstKept, (string) $trimmed[0]->getContent());
        $this->assertSame($total, $trimmer->getTotalTokens());
    }

    public function test_without_usage_a_turn_overflowing_the_tolerance_is_dropped_and_the_total_follows(): void
    {
        // Each user message is estimated at 12 tokens, each assistant message at 13: 75 in total.
        $messages = [
            new UserMessage('Hello'), new AssistantMessage('Hello'),
            new UserMessage('Hello'), new AssistantMessage('Hello'),
            new UserMessage('Hello'), new AssistantMessage('Hello'),
        ];
        $trimmer = new HistoryTrimmer();

        // The last three messages (38 tokens) fit, but keeping their whole turn would be 50, 25% over.
        $trimmed = $trimmer->trim($messages, 40);

        $this->assertSame($this->ids(array_slice($messages, 4)), $this->ids($trimmed));
        $this->assertSame(25, $trimmer->getTotalTokens());
    }

    public function test_a_cut_inside_a_tool_chain_moves_forward_when_keeping_the_turn_overflows(): void
    {
        $messages = (new Conversation(50))
            ->user(20, 'first')
            ->toolCall(10, 'call-1')->toolResult(20, 'call-1')
            ->toolCall(10, 'call-2')->toolResult(20, 'call-2')
            ->toolCall(10, 'call-3')->toolResult(20, 'call-3')
            ->answer(10, 'done')
            ->user(20, 'second')->answer(20, 'ok')
            ->messages();
        $trimmer = new HistoryTrimmer();

        // The cut lands on the last answer of the first turn: keeping that turn would be 210 tokens, twice the window.
        $trimmed = $trimmer->trim($messages, 100);

        $this->assertSame($this->ids(array_slice($messages, 8)), $this->ids($trimmed));
        // 210 minus the first turn's 120 tokens of messages
        $this->assertSame(90, $trimmer->getTotalTokens());
        $this->assertSame(70, $trimmed[1]->getUsage()?->inputTokens);
    }

    public function test_the_latest_user_turn_is_kept_even_when_it_alone_exceeds_the_window(): void
    {
        $messages = (new Conversation(50))->user(12, 'Hello')->answer(20, 'Hi')->user(1000, 'Read this')->messages();
        $trimmer = new HistoryTrimmer();

        $trimmed = $trimmer->trim($messages, 50);

        $this->assertSame([$messages[2]], $trimmed);
        // The instructions and tools, and the 1,000-token question
        $this->assertSame(1050, $trimmer->getTotalTokens());
    }

    public function test_a_history_without_a_user_message_to_start_from_is_kept_whole(): void
    {
        // Only a UserMessage can open a trimmed history; a bare user-role message cannot.
        $messages = [
            new Message(MessageRole::USER, 'Hello'),
            (new AssistantMessage('Hi'))->setUsage(new Usage(100, 20)),
            new Message(MessageRole::USER, 'Hello again'),
            (new AssistantMessage('Hi again'))->setUsage(new Usage(200, 20)),
        ];

        $this->assertSame($messages, (new HistoryTrimmer())->trim($messages, 100));
    }

    public function test_consecutive_tool_rounds_are_a_valid_sequence(): void
    {
        $first = new ToolCall('a', 'call-1');
        $second = new ToolCall('b', 'call-2');
        $messages = [
            new UserMessage('Go'),
            new ToolCallMessage(null, [$first]),
            new ToolResultMessage([(clone $first)->setResult('1')]),
            new ToolCallMessage('Now the second', [$second]),
            new ToolResultMessage([(clone $second)->setResult('2')]),
            new AssistantMessage('Done'),
            new UserMessage('Thanks'),
        ];

        $this->assertSame($messages, (new HistoryTrimmer())->trim($messages, 100000));
    }

    /**
     * @return array<string, array{callable(): Message[], string}>
     */
    public static function invalidSequences(): array
    {
        $call = static fn (): ToolCall => new ToolCall('lookup', 'call-1');
        $result = static fn (): ToolResultMessage => new ToolResultMessage([$call()->setResult('found')]);

        return [
            'starts with an assistant' => [
                static fn (): array => [new AssistantMessage('Hi')],
                'Invalid message sequence at position 0: expected role user, got assistant',
            ],
            'starts with a tool call' => [
                static fn (): array => [new ToolCallMessage(null, [$call()]), $result()],
                'Invalid message sequence at position 0: expected role user, got assistant',
            ],
            'starts with a tool result' => [
                static fn (): array => [$result()],
                'Invalid message sequence: ToolResultMessage at position 0 must follow a ToolCallMessage',
            ],
            'tool result after an assistant' => [
                static fn (): array => [new UserMessage('Hi'), new AssistantMessage('Hello'), $result()],
                'Invalid message sequence: ToolResultMessage at position 2 must follow a ToolCallMessage',
            ],
            'user after a tool call' => [
                static fn (): array => [new UserMessage('Hi'), new ToolCallMessage(null, [$call()]), new UserMessage('Again')],
                'Invalid message sequence at position 2: a UserMessage cannot directly follow a ToolCallMessage',
            ],
            'assistant after a tool call' => [
                static fn (): array => [new UserMessage('Hi'), new ToolCallMessage(null, [$call()]), new AssistantMessage('Skipped')],
                'Invalid message sequence at position 2: expected role user, got assistant',
            ],
            'tool call without the assistant role' => [
                static fn (): array => [new UserMessage('Hi'), (new ToolCallMessage(null, [$call()]))->setRole(MessageRole::MODEL)],
                'Invalid message sequence: ToolCallMessage at position 1 must have ASSISTANT role, got model',
            ],
            'user after a tool result' => [
                static fn (): array => [new UserMessage('Hi'), new ToolCallMessage(null, [$call()]), $result(), new UserMessage('Again')],
                'Invalid message sequence at position 3: expected role assistant, got user',
            ],
            'two assistants' => [
                static fn (): array => [new UserMessage('Hi'), new AssistantMessage('One'), new AssistantMessage('Two')],
                'Invalid message sequence at position 2: expected role user, got assistant',
            ],
            'a system message' => [
                static fn (): array => [new Message(MessageRole::SYSTEM, 'Be concise.')],
                'Invalid message sequence at position 0: expected role user, got system',
            ],
        ];
    }

    /**
     * @param callable(): Message[] $messages
     */
    #[DataProvider('invalidSequences')]
    public function test_an_invalid_sequence_is_rejected(callable $messages, string $error): void
    {
        $sequence = $messages();

        $this->expectException(ChatHistoryException::class);
        $this->expectExceptionMessage($error);

        (new HistoryTrimmer())->trim($sequence, 100000);
    }

    public function test_the_sequence_is_validated_after_trimming(): void
    {
        $messages = (new Conversation(50))
            ->user(11, 'Hi')->answer(20, 'Hello')
            ->user(13, 'Look it up')->toolCall(20, 'call-1')
            ->user(14, 'Never answered')
            ->messages();

        $this->expectException(ChatHistoryException::class);
        $this->expectExceptionMessage('position 2: a UserMessage cannot directly follow a ToolCallMessage');

        (new HistoryTrimmer())->trim($messages, 100);
    }

    /**
     * @return Message[]
     */
    protected function toolRoundConversation(): array
    {
        // 185 tokens: 50 of instructions and tools, a 30-token turn, a 90-token tool round and a 35-token turn
        return (new Conversation(50))
            ->user(20, 'first')->answer(10, 'ok')
            ->user(20, 'second')->toolCall(10, 'call-1')->toolResult(20, 'call-1')->answer(20, 'found it')
            ->user(20, 'third')->answer(15, 'done')
            ->messages();
    }

    /**
     * 50 tokens of instructions and tools, then two turns: 100 + 20 and 100 + 30.
     * The provider reports 150 + 20 after the first answer and 270 + 30 after the second.
     *
     * @return Message[]
     */
    protected function conversation(): array
    {
        return (new Conversation(50))->user(100, 'first')->answer(20, 'one')->user(100, 'second')->answer(30, 'two')->messages();
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
