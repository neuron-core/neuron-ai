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
use NeuronAI\Tools\ToolCall;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function array_map;
use function array_slice;
use function str_repeat;

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

        $this->assertSame($messages, $trimmer->trim($messages, 280));
        $this->assertSame(280, $trimmer->getTotalTokens());
    }

    public function test_one_token_over_the_window_drops_the_oldest_turn(): void
    {
        $messages = $this->conversation();
        $trimmer = new HistoryTrimmer();

        $trimmed = $trimmer->trim($messages, 279);

        $this->assertSame($this->ids(array_slice($messages, 2)), $this->ids($trimmed));
        $this->assertSame(160, $trimmer->getTotalTokens());
    }

    public function test_a_cut_that_lands_exactly_on_the_window_keeps_the_next_turn(): void
    {
        $messages = $this->conversation();
        $trimmer = new HistoryTrimmer();

        // Dropping the first turn (120 of 280 tokens) leaves exactly the 160-token window.
        $trimmed = $trimmer->trim($messages, 160);

        $this->assertSame($this->ids(array_slice($messages, 2)), $this->ids($trimmed));
        $this->assertSame(160, $trimmer->getTotalTokens());
        $this->assertSame(130, $trimmed[1]->getUsage()?->inputTokens);
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

    public function test_the_kept_checkpoints_are_rebased_on_the_trimmed_context(): void
    {
        $trimmer = new HistoryTrimmer();

        $trimmed = $trimmer->trim($this->conversation(), 279);

        $usage = $trimmed[1]->getUsage();
        $this->assertInstanceOf(Usage::class, $usage);
        $this->assertSame(130, $usage->inputTokens);
        $this->assertSame(30, $usage->outputTokens);
    }

    public function test_a_rebased_checkpoint_keeps_its_cache_and_reasoning_counts(): void
    {
        $messages = [
            new UserMessage('q1'),
            (new AssistantMessage('a1'))->setUsage(new Usage(100, 50)),
            new UserMessage('q2'),
            (new AssistantMessage('a2'))->setUsage(new Usage(300, 50, 200, 20)),
        ];

        $trimmed = (new HistoryTrimmer())->trim($messages, 300);

        $this->assertSame(
            ['input_tokens' => 150, 'output_tokens' => 50, 'cached_input_tokens' => 200, 'reasoning_tokens' => 20],
            $trimmed[1]->getUsage()?->jsonSerialize()
        );
    }

    public function test_a_rebased_checkpoint_never_reports_negative_input_tokens(): void
    {
        // Providers that bill cached input apart (e.g. Anthropic) report input
        // tokens smaller than the context the earlier turns built up.
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
        $trimmed = $trimmer->trim($this->conversation(), 279);

        $this->assertSame($trimmed, $trimmer->trim($trimmed, 279));
        $this->assertSame(160, $trimmer->getTotalTokens());
    }

    public function test_without_usage_the_oldest_messages_are_dropped_by_estimation(): void
    {
        // Each user message is estimated at 12 tokens, each assistant message at 13.
        $messages = [new UserMessage('Hello'), new AssistantMessage('Hello'), new UserMessage('Hello'), new AssistantMessage('Hello')];

        $trimmed = (new HistoryTrimmer())->trim($messages, 30);

        $this->assertSame($this->ids(array_slice($messages, 2)), $this->ids($trimmed));
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

        // Only the first turn (15 tokens) is dropped, not the kept tool call's 70.
        $this->assertSame(155, $trimmer->getTotalTokens());
        $this->assertSame(45, $trimmed[1]->getUsage()?->inputTokens);
        $this->assertSame(135, $trimmed[5]->getUsage()?->inputTokens);
    }

    /**
     * @return array<string, array{int, string, int}>
     */
    public static function overflowTolerance(): array
    {
        return [
            'one percent over keeps the turn' => [99, 'second', 101],
            'exactly five percent over keeps the turn' => [103, 'second', 105],
            'beyond five percent cuts at the next turn' => [104, 'third', 20],
        ];
    }

    #[DataProvider('overflowTolerance')]
    public function test_a_turn_is_kept_while_the_overflow_stays_within_five_percent(int $lastInput, string $firstKept, int $total): void
    {
        $call = new ToolCall('lookup', 'call-1');
        $messages = [
            new UserMessage('first'),
            (new AssistantMessage('one'))->setUsage(new Usage(2, 1)),
            new UserMessage('second'),
            (new ToolCallMessage(null, [$call]))->setUsage(new Usage(12, 1)),
            new ToolResultMessage([(clone $call)->setResult('found')]),
            (new AssistantMessage('two'))->setUsage(new Usage(88, 1)),
            new UserMessage('third'),
            (new AssistantMessage('three'))->setUsage(new Usage($lastInput, 5)),
        ];
        $trimmer = new HistoryTrimmer();

        // The smallest cut lands on the tool result: keeping the second turn costs 3 tokens less than everything.
        $trimmed = $trimmer->trim($messages, 100);

        $this->assertSame($firstKept, $trimmed[0]->getContent());
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
        $calls = [new ToolCall('a', 'call-1'), new ToolCall('b', 'call-2'), new ToolCall('c', 'call-3')];
        $messages = [
            new UserMessage('first'),
            (new ToolCallMessage(null, [$calls[0]]))->setUsage(new Usage(50, 10)),
            new ToolResultMessage([(clone $calls[0])->setResult('1')]),
            (new ToolCallMessage(null, [$calls[1]]))->setUsage(new Usage(100, 10)),
            new ToolResultMessage([(clone $calls[1])->setResult('2')]),
            (new ToolCallMessage(null, [$calls[2]]))->setUsage(new Usage(150, 10)),
            new ToolResultMessage([(clone $calls[2])->setResult('3')]),
            (new AssistantMessage('done'))->setUsage(new Usage(200, 10)),
            new UserMessage('second'),
            (new AssistantMessage('ok'))->setUsage(new Usage(300, 20)),
        ];
        $trimmer = new HistoryTrimmer();

        // The cut lands on the third tool result: keeping the whole first turn would be 320 tokens, 60% over.
        $trimmed = $trimmer->trim($messages, 200);

        $this->assertSame($this->ids(array_slice($messages, 8)), $this->ids($trimmed));
        // 320 minus the 210 tokens reported by the last dropped message.
        $this->assertSame(110, $trimmer->getTotalTokens());
        $this->assertSame(90, $trimmed[1]->getUsage()?->inputTokens);
    }

    public function test_the_latest_user_turn_is_kept_even_when_it_alone_exceeds_the_window(): void
    {
        $messages = [
            new UserMessage('Hello'),
            (new AssistantMessage('Hi'))->setUsage(new Usage(100, 20)),
            new UserMessage(str_repeat('a', 4000)),
        ];
        $trimmer = new HistoryTrimmer();

        $trimmed = $trimmer->trim($messages, 50);

        $this->assertSame([$messages[2]], $trimmed);
        // 4 role chars + 38 + 4000 content chars, estimated
        $this->assertSame(1011, $trimmer->getTotalTokens());
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
        $call = new ToolCall('lookup', 'call-1');
        $messages = [
            new UserMessage('Hi'),
            (new AssistantMessage('Hello'))->setUsage(new Usage(100, 20)),
            new UserMessage('Look it up'),
            (new ToolCallMessage(null, [$call]))->setUsage(new Usage(200, 20)),
            new UserMessage('Never answered'),
        ];

        $this->expectException(ChatHistoryException::class);
        $this->expectExceptionMessage('position 2: a UserMessage cannot directly follow a ToolCallMessage');

        (new HistoryTrimmer())->trim($messages, 150);
    }

    /**
     * @return Message[]
     */
    protected function toolRoundConversation(): array
    {
        $call = new ToolCall('lookup', 'call-1', ['q' => 'x']);

        return [
            new UserMessage('first'),
            (new AssistantMessage('ok'))->setUsage(new Usage(10, 5)),
            new UserMessage('second'),
            (new ToolCallMessage(null, [$call]))->setUsage(new Usage(60, 10)),
            new ToolResultMessage([(clone $call)->setResult('found')]),
            (new AssistantMessage('found it'))->setUsage(new Usage(100, 20)),
            new UserMessage('third'),
            (new AssistantMessage('done'))->setUsage(new Usage(150, 20)),
        ];
    }

    /**
     * Two turns: the provider reports 120 tokens after the first, 280 after the second.
     *
     * @return Message[]
     */
    protected function conversation(): array
    {
        return [
            new UserMessage('first'),
            (new AssistantMessage('one'))->setUsage(new Usage(100, 20)),
            new UserMessage('second'),
            (new AssistantMessage('two'))->setUsage(new Usage(250, 30)),
        ];
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
