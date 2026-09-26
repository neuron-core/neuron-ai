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
        $call = new ToolCall('lookup', 'call-1', ['q' => 'x']);
        $messages = [
            new UserMessage('first'),
            (new AssistantMessage('ok'))->setUsage(new Usage(10, 5)),
            new UserMessage('second'),
            (new ToolCallMessage(null, [$call]))->setUsage(new Usage(60, 10)),
            new ToolResultMessage([(clone $call)->setResult('found')]),
            (new AssistantMessage('found it'))->setUsage(new Usage(100, 20)),
            new UserMessage('third'),
            (new AssistantMessage('done'))->setUsage(new Usage(150, 20)),
        ];

        // The first checkpoint past the overflow is the tool call: the cut would land on its result.
        $trimmed = (new HistoryTrimmer())->trim($messages, 150);

        $this->assertSame($this->ids(array_slice($messages, 2)), $this->ids($trimmed));
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
