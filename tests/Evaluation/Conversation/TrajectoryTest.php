<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Evaluation\Conversation;

use NeuronAI\Chat\Enums\SourceType;
use NeuronAI\Chat\History\ChatHistory;
use NeuronAI\Chat\History\InMemoryMessageStore;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ContentBlocks\FileContent;
use NeuronAI\Chat\Messages\ContentBlocks\ImageContent;
use NeuronAI\Chat\Messages\ContentBlocks\TextContent;
use NeuronAI\Chat\Messages\SystemMessage;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Chat\Messages\Usage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Evaluation\Conversation\Trajectory;
use NeuronAI\Tools\ApprovalState;
use NeuronAI\Tools\ToolCall;
use PHPUnit\Framework\TestCase;

use function array_map;
use function serialize;
use function unserialize;

class TrajectoryTest extends TestCase
{
    protected function makeTool(string $name, array $inputs = [], ?string $callId = null): ToolCall
    {
        return ToolCall::make($name, description: "The {$name} tool")
            ->setInputs($inputs)
            ->setCallId($callId);
    }

    public function test_wraps_original_messages(): void
    {
        $messages = [
            new SystemMessage('You are a helpful assistant.'),
            new UserMessage('Hello'),
            new AssistantMessage('Hi! How can I help?'),
            new UserMessage('What is the capital of France?'),
            new AssistantMessage('The capital of France is Paris.'),
        ];

        $trajectory = Trajectory::fromMessages($messages);

        $this->assertSame($messages, $trajectory->messages());
        $this->assertSame(5, $trajectory->count());
        $this->assertSame(['Hello', 'What is the capital of France?'], $trajectory->userMessages());
        $this->assertSame('The capital of France is Paris.', $trajectory->finalAnswer());
    }

    public function test_tool_round_trip_folds_to_final_outcome(): void
    {
        $pending = $this->makeTool('get_weather', ['city' => 'Rome'], 'call_1');
        $executed = $this->makeTool('get_weather', ['city' => 'Rome'], 'call_1')
            ->setResult('Sunny, 24C');

        $trajectory = Trajectory::fromMessages([
            new UserMessage('Weather in Rome?'),
            new ToolCallMessage('Let me check.', [$pending]),
            new ToolResultMessage([$executed]),
            new AssistantMessage('It is sunny in Rome.'),
        ]);

        $calls = $trajectory->toolCalls();
        $this->assertCount(1, $calls);
        // The final-outcome entry from the result message wins over the pending snapshot.
        $this->assertSame($executed, $calls[0]);
        $this->assertSame('Sunny, 24C', $calls[0]->getResult());
        $this->assertSame(['city' => 'Rome'], $calls[0]->getInputs());
        $this->assertSame('It is sunny in Rome.', $trajectory->finalAnswer());
    }

    public function test_suspended_tail_keeps_pending_entry(): void
    {
        $gated = $this->makeTool('refund_order', ['order_id' => '123'], 'call_9');
        $gated->setApprovalState(ApprovalState::Pending);
        $gated->setApprovalReason('Refunds move money');

        $trajectory = Trajectory::fromMessages([
            new UserMessage('Refund my order'),
            new ToolCallMessage(null, [$gated]),
        ]);

        $call = $trajectory->lastToolCall('refund_order');
        $this->assertInstanceOf(ToolCall::class, $call);
        $this->assertSame(ApprovalState::Pending, $call->getApprovalState());
        $this->assertSame('Refunds move money', $call->getApprovalReason());
        $this->assertSame('', $trajectory->finalAnswer());
    }

    public function test_final_outcome_overrides_pending_snapshot(): void
    {
        // The tool_call message keeps its pending snapshot forever;
        // the final outcome lives on the ToolResultMessage and must win.
        $pending = $this->makeTool('refund_order', ['order_id' => '123'], 'call_9');
        $pending->setApprovalState(ApprovalState::Pending);

        $rejected = $this->makeTool('refund_order', ['order_id' => '123'], 'call_9');
        $rejected->setApprovalState(ApprovalState::Rejected, 'Amount too high');
        $rejected->setResult('rejected by the user');

        $trajectory = Trajectory::fromMessages([
            new UserMessage('Refund my order'),
            new ToolCallMessage(null, [$pending]),
            new ToolResultMessage([$rejected]),
            new AssistantMessage('I cannot process the refund.'),
        ]);

        $call = $trajectory->lastToolCall('refund_order');
        $this->assertInstanceOf(ToolCall::class, $call);
        $this->assertSame(ApprovalState::Rejected, $call->getApprovalState());
        $this->assertSame('Amount too high', $call->getRejectReason());
        $this->assertSame('rejected by the user', $call->getResult());
    }

    public function test_parallel_calls_of_the_same_tool_match_by_call_id(): void
    {
        // Providers stamp a unique callId on every call, including parallel
        // calls of the same tool (Gemini synthesizes one when the API omits it).
        $first = $this->makeTool('search', ['q' => 'a'], 'call_a');
        $second = $this->makeTool('search', ['q' => 'b'], 'call_b');

        $firstDone = $this->makeTool('search', ['q' => 'a'], 'call_a')->setResult('result A');
        $secondDone = $this->makeTool('search', ['q' => 'b'], 'call_b')->setResult('result B');

        $trajectory = Trajectory::fromMessages([
            new UserMessage('Search a and b'),
            new ToolCallMessage(null, [$first, $second]),
            new ToolResultMessage([$firstDone, $secondDone]),
            new AssistantMessage('Done.'),
        ]);

        $calls = $trajectory->toolCalls('search');
        $this->assertCount(2, $calls);
        $this->assertSame('result A', $calls[0]->getResult());
        $this->assertSame('result B', $calls[1]->getResult());
    }

    public function test_tool_calls_filter_and_last_tool_call(): void
    {
        $search = $this->makeTool('search', ['q' => 'x'], 'call_1');
        $searchDone = $this->makeTool('search', ['q' => 'x'], 'call_1')->setResult('found');
        $mail = $this->makeTool('send_email', ['to' => 'a@b.c'], 'call_2');
        $mailDone = $this->makeTool('send_email', ['to' => 'a@b.c'], 'call_2')->setResult('sent');

        $trajectory = Trajectory::fromMessages([
            new UserMessage('Do things'),
            new ToolCallMessage(null, [$search]),
            new ToolResultMessage([$searchDone]),
            new ToolCallMessage(null, [$mail]),
            new ToolResultMessage([$mailDone]),
            new AssistantMessage('All done.'),
        ]);

        $this->assertCount(2, $trajectory->toolCalls());
        $this->assertCount(1, $trajectory->toolCalls('send_email'));
        $last = $trajectory->lastToolCall();
        $this->assertInstanceOf(ToolCall::class, $last);
        $this->assertSame('send_email', $last->getName());
        $this->assertNull($trajectory->lastToolCall('unknown_tool'));
    }

    public function test_from_chat_history(): void
    {
        $history = new ChatHistory(new InMemoryMessageStore(), 'thread');
        $history->addMessage(new UserMessage('Hello'));
        $history->addMessage(new AssistantMessage('Hi there.'));

        $trajectory = Trajectory::fromChatHistory($history);

        $this->assertCount(2, $trajectory->messages());
        $this->assertSame('Hi there.', $trajectory->finalAnswer());
    }

    public function test_usage_aggregation(): void
    {
        $first = new AssistantMessage('Hi!');
        $first->setUsage(new Usage(100, 20));
        $second = new AssistantMessage('Paris.');
        $second->setUsage(new Usage(150, 30));

        $trajectory = Trajectory::fromMessages([
            new UserMessage('Hello'),
            $first,
            new UserMessage('Capital of France?'),
            $second,
        ]);

        $usage = $trajectory->usage();
        $this->assertSame(250, $usage->inputTokens);
        $this->assertSame(50, $usage->outputTokens);
        $this->assertSame(300, $usage->getTotal());
    }

    public function test_survives_serialization_across_fork_boundary(): void
    {
        $pending = $this->makeTool('refund_order', ['order_id' => '123'], 'call_9');
        $pending->setApprovalState(ApprovalState::Pending);

        $rejected = $this->makeTool('refund_order', ['order_id' => '123'], 'call_9');
        $rejected->setApprovalState(ApprovalState::Rejected, 'Too expensive');
        $rejected->setResult('rejected by the user');

        $trajectory = Trajectory::fromMessages([
            new UserMessage('Refund my order'),
            new ToolCallMessage(null, [$pending]),
            new ToolResultMessage([$rejected]),
            new AssistantMessage('I cannot do that.'),
        ]);

        $restored = unserialize(serialize($trajectory));

        $this->assertInstanceOf(Trajectory::class, $restored);
        $this->assertSame($trajectory->count(), $restored->count());
        $this->assertSame('I cannot do that.', $restored->finalAnswer());
        $call = $restored->lastToolCall('refund_order');
        $this->assertInstanceOf(ToolCall::class, $call);
        $this->assertSame(ApprovalState::Rejected, $call->getApprovalState());
        $this->assertSame('Too expensive', $call->getRejectReason());
    }

    public function test_to_transcript_rendering(): void
    {
        $pending = $this->makeTool('refund_order', ['order_id' => '123'], 'call_9');
        $pending->setApprovalState(ApprovalState::Pending);

        $rejected = $this->makeTool('refund_order', ['order_id' => '123'], 'call_9');
        $rejected->setApprovalReason('Refunds move money');
        $rejected->setApprovalState(ApprovalState::Rejected, 'Too expensive');
        $rejected->setResult('rejected by the user');

        $trajectory = Trajectory::fromMessages([
            new SystemMessage('You are a refund assistant.'),
            new UserMessage('Refund order 123'),
            new ToolCallMessage('Let me handle that.', [$pending]),
            new ToolResultMessage([$rejected]),
            new AssistantMessage('I cannot process the refund.'),
        ]);

        $transcript = $trajectory->toTranscript();

        $this->assertStringNotContainsString('refund assistant', $transcript);
        $this->assertStringContainsString('User: Refund order 123', $transcript);
        $this->assertStringContainsString('Assistant: Let me handle that.', $transcript);
        $this->assertStringContainsString('Tool call: refund_order({"order_id":"123"})', $transcript);
        $this->assertStringContainsString('(approval requested: Refunds move money)', $transcript);
        $this->assertStringContainsString('[rejected: Too expensive]', $transcript);
        $this->assertStringContainsString('Tool result (refund_order): rejected by the user', $transcript);
        $this->assertStringContainsString('Assistant: I cannot process the refund.', $transcript);
    }

    public function test_pending_approval_renders_in_transcript_without_result(): void
    {
        $pending = $this->makeTool('refund_order', ['order_id' => '123'], 'call_9');
        $pending->setApprovalState(ApprovalState::Pending);

        $trajectory = Trajectory::fromMessages([
            new UserMessage('Refund order 123'),
            new ToolCallMessage(null, [$pending]),
        ]);

        $transcript = $trajectory->toTranscript();

        $this->assertStringContainsString('[pending approval]', $transcript);
        $this->assertStringNotContainsString('Tool result', $transcript);
    }

    public function test_attachments_render_in_transcript(): void
    {
        $trajectory = Trajectory::fromMessages([
            new UserMessage([
                new TextContent('Refund this receipt'),
                new ImageContent('https://example.com/receipt.jpg', SourceType::URL, 'image/jpeg'),
                new FileContent('JVBERi0xLjQ=', SourceType::BASE64, 'application/pdf', 'invoice.pdf'),
            ]),
            new AssistantMessage('I see the receipt and the invoice.'),
        ]);

        $transcript = $trajectory->toTranscript();

        $this->assertStringContainsString('User: Refund this receipt', $transcript);
        $this->assertStringContainsString('[attached: image (image/jpeg), file "invoice.pdf" (application/pdf)]', $transcript);
    }

    public function test_result_with_an_unknown_call_id_is_ignored(): void
    {
        $pending = $this->makeTool('refund_order', ['order_id' => '1'], 'call_1');
        $pending->setApprovalState(ApprovalState::Pending);
        $forged = $this->makeTool('refund_order', ['order_id' => '999'], 'call_forged');
        $forged->setApprovalState(ApprovalState::Approved);
        $forged->setResult('refunded 999');

        $trajectory = Trajectory::fromMessages([
            new UserMessage('Refund order 1'),
            new ToolCallMessage(null, [$pending]),
            new ToolResultMessage([$forged]),
        ]);

        $calls = $trajectory->toolCalls();
        $this->assertCount(1, $calls);
        $this->assertSame($pending, $calls[0]);
        $this->assertSame(ApprovalState::Pending, $calls[0]->getApprovalState());
        $this->assertFalse($calls[0]->hasResult());
    }

    public function test_result_without_a_preceding_tool_call_adds_no_call(): void
    {
        $orphan = $this->makeTool('refund_order', ['order_id' => '1'], 'call_1')->setResult('refunded');

        $trajectory = Trajectory::fromMessages([
            new UserMessage('Hello'),
            new ToolResultMessage([$orphan]),
            new AssistantMessage('Hi.'),
        ]);

        $this->assertSame([], $trajectory->toolCalls());
        $this->assertNull($trajectory->lastToolCall());
    }

    public function test_a_result_only_resolves_calls_of_the_immediately_preceding_round(): void
    {
        $first = $this->makeTool('search', ['q' => 'a'], 'call_1');
        $firstDone = $this->makeTool('search', ['q' => 'a'], 'call_1')->setResult('A');
        $second = $this->makeTool('search', ['q' => 'b'], 'call_2');
        // A late duplicate of the first round's result must not rewrite history
        $replayed = $this->makeTool('search', ['q' => 'a'], 'call_1')->setResult('tampered');

        $trajectory = Trajectory::fromMessages([
            new ToolCallMessage(null, [$first]),
            new ToolResultMessage([$firstDone]),
            new ToolCallMessage(null, [$second]),
            new ToolResultMessage([$replayed]),
        ]);

        $calls = $trajectory->toolCalls();
        $this->assertCount(2, $calls);
        $this->assertSame('A', $calls[0]->getResult());
        $this->assertSame($second, $calls[1]);
        $this->assertFalse($calls[1]->hasResult());
    }

    public function test_a_duplicated_result_message_does_not_rewrite_a_resolved_call(): void
    {
        $call = $this->makeTool('refund_order', ['order_id' => '1'], 'call_1');
        $resolved = $this->makeTool('refund_order', ['order_id' => '1'], 'call_1')->setResult('refunded');
        $duplicate = $this->makeTool('refund_order', ['order_id' => '1'], 'call_1')->setResult('tampered');

        $trajectory = Trajectory::fromMessages([
            new ToolCallMessage(null, [$call]),
            new ToolResultMessage([$resolved]),
            new ToolResultMessage([$duplicate]),
        ]);

        $this->assertSame([$resolved], $trajectory->toolCalls());
    }

    public function test_calls_without_call_ids_fold_by_tool_name_round_by_round(): void
    {
        $first = ToolCall::make('search')->setInputs(['q' => 'a']);
        $firstDone = ToolCall::make('search')->setInputs(['q' => 'a'])->setResult('A');
        $second = ToolCall::make('search')->setInputs(['q' => 'b']);
        $secondDone = ToolCall::make('search')->setInputs(['q' => 'b'])->setResult('B');

        $trajectory = Trajectory::fromMessages([
            new ToolCallMessage(null, [$first]),
            new ToolResultMessage([$firstDone]),
            new ToolCallMessage(null, [$second]),
            new ToolResultMessage([$secondDone]),
        ]);

        $this->assertSame(['A', 'B'], array_map(
            static fn (ToolCall $call): mixed => $call->getResult(),
            $trajectory->toolCalls()
        ));
    }

    public function test_user_messages_exclude_tool_results_and_empty_contents(): void
    {
        $tool = $this->makeTool('search', ['q' => 'x'], 'call_1')->setResult('found');

        $trajectory = Trajectory::fromMessages([
            new UserMessage('First'),
            new ToolCallMessage(null, [$tool]),
            // Tool results travel in user-role messages: text they carry is not a user turn
            (new ToolResultMessage([$tool]))->addContent(new TextContent('found')),
            new UserMessage(''),
            new UserMessage('Second'),
        ]);

        $this->assertSame(['First', 'Second'], $trajectory->userMessages());
    }

    public function test_final_answer_skips_trailing_empty_assistant_messages(): void
    {
        $trajectory = Trajectory::fromMessages([
            new UserMessage('Hi'),
            new AssistantMessage('Hello!'),
            new AssistantMessage(''),
        ]);

        $this->assertSame('Hello!', $trajectory->finalAnswer());
    }

    public function test_messages_are_reindexed_as_a_list(): void
    {
        $user = new UserMessage('Hi');
        $assistant = new AssistantMessage('Hello');

        $trajectory = Trajectory::fromMessages([5 => $user, 9 => $assistant]);

        $this->assertSame([$user, $assistant], $trajectory->messages());
    }

    public function test_empty_trajectory(): void
    {
        $trajectory = Trajectory::fromMessages([]);

        $this->assertSame(0, $trajectory->count());
        $this->assertSame([], $trajectory->toolCalls());
        $this->assertSame([], $trajectory->userMessages());
        $this->assertSame('', $trajectory->finalAnswer());
        $this->assertSame('', $trajectory->toTranscript());
        $this->assertSame(0, $trajectory->usage()->getTotal());
    }

    public function test_usage_sums_every_token_counter_and_skips_messages_without_usage(): void
    {
        $first = new AssistantMessage('Hi!');
        $first->setUsage(new Usage(100, 20, cachedInputTokens: 40, reasoningTokens: 5));
        $second = new AssistantMessage('Paris.');
        $second->setUsage(new Usage(150, 30, cachedInputTokens: 60, reasoningTokens: 7));

        $usage = Trajectory::fromMessages([new UserMessage('Hello'), $first, new UserMessage('Capital?'), $second])->usage();

        $this->assertSame(250, $usage->inputTokens);
        $this->assertSame(50, $usage->outputTokens);
        $this->assertSame(100, $usage->cachedInputTokens);
        $this->assertSame(12, $usage->reasoningTokens);
    }

    public function test_usage_does_not_mutate_the_messages_usage(): void
    {
        $answer = new AssistantMessage('Hi!');
        $answer->setUsage(new Usage(10, 5));
        $trajectory = Trajectory::fromMessages([$answer]);

        $trajectory->usage();
        $trajectory->usage();

        $this->assertSame(10, $answer->getUsage()?->inputTokens);
        $this->assertSame(10, $trajectory->usage()->inputTokens);
    }

    public function test_transcript_is_exact(): void
    {
        $approved = $this->makeTool('search', ['q' => 'refund policy'], 'call_1');
        $approved->setApprovalState(ApprovalState::Approved);
        $approved->setResult('30 days');
        $plain = $this->makeTool('lookup_order', ['id' => '123'], 'call_2')->setResult('delivered');
        $rejected = $this->makeTool('refund_order', ['id' => '123'], 'call_3');
        $rejected->setApprovalState(ApprovalState::Rejected);

        $trajectory = Trajectory::fromMessages([
            new SystemMessage('Hidden instructions'),
            new UserMessage('Can I get a refund?'),
            new ToolCallMessage(null, [$approved, $plain]),
            new ToolResultMessage([$approved, $plain]),
            new ToolCallMessage('Refunding now.', [$rejected]),
            new ToolResultMessage([$rejected]),
            new AssistantMessage('The refund was declined.'),
        ]);

        $this->assertSame(
            "User: Can I get a refund?\n"
            . "Tool call: search({\"q\":\"refund policy\"}) [approved]\n"
            . "Tool result (search): 30 days\n"
            . "Tool call: lookup_order({\"id\":\"123\"})\n"
            . "Tool result (lookup_order): delivered\n"
            . "Assistant: Refunding now.\n"
            . "Tool call: refund_order({\"id\":\"123\"}) [rejected]\n"
            . 'Assistant: The refund was declined.',
            $trajectory->toTranscript()
        );
    }

    public function test_serialization_round_trip_preserves_the_transcript_and_usage(): void
    {
        $tool = $this->makeTool('refund_order', ['order_id' => '123'], 'call_9');
        $tool->setApprovalReason('Refunds move money');
        $tool->setApprovalState(ApprovalState::Approved);
        $tool->setResult('refunded');
        $answer = new AssistantMessage('Refunded — ünïcödé ✓');
        $answer->setUsage(new Usage(12, 34));

        $trajectory = Trajectory::fromMessages([
            new UserMessage([
                new TextContent('Refund this receipt'),
                new ImageContent('https://example.com/receipt.jpg', SourceType::URL, 'image/jpeg'),
            ]),
            new ToolCallMessage(null, [$tool]),
            new ToolResultMessage([$tool]),
            $answer,
        ]);

        $restored = unserialize(serialize($trajectory));

        $this->assertInstanceOf(Trajectory::class, $restored);
        $this->assertSame($trajectory->toTranscript(), $restored->toTranscript());
        $this->assertSame(12, $restored->usage()->inputTokens);
        $this->assertSame(34, $restored->usage()->outputTokens);
        $this->assertSame(['Refund this receipt'], $restored->userMessages());
    }
}
