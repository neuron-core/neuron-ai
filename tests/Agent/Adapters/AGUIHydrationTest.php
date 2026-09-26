<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Agent\Adapters;

use NeuronAI\Agent\Adapters\AGUIAdapter;
use NeuronAI\Agent\Agent;
use NeuronAI\Agent\Frontend\AGUIInputTranslator;
use NeuronAI\Agent\Events\AgentStartEvent;
use NeuronAI\Agent\Interrupt\Action;
use NeuronAI\Agent\Interrupt\ApprovalRequest;
use NeuronAI\Agent\Interrupt\ToolResultsRequest;
use NeuronAI\Chat\Enums\SourceType;
use NeuronAI\Chat\History\InMemoryMessageStore;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ContentBlocks\AudioContent;
use NeuronAI\Chat\Messages\ContentBlocks\FileContent;
use NeuronAI\Chat\Messages\ContentBlocks\ImageContent;
use NeuronAI\Chat\Messages\ContentBlocks\ReasoningContent;
use NeuronAI\Chat\Messages\ContentBlocks\TextContent;
use NeuronAI\Chat\Messages\ContentBlocks\VideoContent;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\Stream\Chunks\ToolResultChunk;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Exceptions\ProviderException;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Tests\Agent\Stub\ClosureDependencyTool;
use NeuronAI\Tests\Tools\Stub\ToolStub;
use NeuronAI\Tools\FrontendTool;
use NeuronAI\Tools\ToolCall;
use NeuronAI\Tools\ToolInterface;
use NeuronAI\Tools\ToolOutput;
use NeuronAI\Workflow\Interrupt\InterruptRequest;
use NeuronAI\Workflow\Persistence\InMemoryPersistence;
use NeuronAI\Workflow\Streaming\ProtocolEvent;
use NeuronAI\Workflow\WorkflowEngine;
use NeuronAI\Workflow\WorkflowRunSnapshot;
use NeuronAI\Workflow\WorkflowStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function array_column;
use function array_filter;
use function array_map;
use function array_slice;
use function array_values;
use function iterator_to_array;
use function json_encode;

use const JSON_THROW_ON_ERROR;

/**
 * A reload gives the client the messages and interrupts it held when the live
 * stream ended, rebuilt from the message store and the persisted run.
 */
class AGUIHydrationTest extends TestCase
{
    protected InMemoryMessageStore $store;

    protected InMemoryPersistence $persistence;

    protected function setUp(): void
    {
        $this->store = new InMemoryMessageStore();
        $this->persistence = new InMemoryPersistence();
    }

    public function test_a_reload_after_an_approval_matches_the_live_stream(): void
    {
        $provider = new FakeAIProvider(new ToolCallMessage(
            [new ReasoningContent('Deleting is irreversible'), new TextContent('Let me delete it')],
            [new ToolCall('delete', 'call_delete')],
        ));

        $live = $this->events($this->agent($provider, (new ToolStub('delete'))->requireApproval())->stream(new UserMessage('Delete the file')));
        $reload = $this->reload();

        $snapshot = $this->first($live, 'MESSAGES_SNAPSHOT');
        $finished = $this->first($live, 'RUN_FINISHED');
        $this->assertSame(['user', 'reasoning', 'assistant'], array_column($reload['messages'], 'role'));
        $this->assertSame($this->store->loadAll('thread')[0]->getId(), $reload['messages'][0]['id']);
        $this->assertSame($this->json($snapshot['messages']), $this->json(array_slice($reload['messages'], 1)));
        $this->assertSame($this->json($finished['outcome']['interrupts']), $this->json($reload['interrupts']));
    }

    public function test_a_reload_after_a_completed_turn_keeps_the_live_ids(): void
    {
        $provider = new FakeAIProvider(
            new ToolCallMessage('Let me check', [new ToolCall('clock', 'call_clock')]),
            new AssistantMessage([new ReasoningContent('Reading the clock'), new TextContent('It is ten')]),
        );

        $live = $this->events($this->agent($provider, new ToolStub('clock'))->stream(new UserMessage('What time is it?')));
        $reload = $this->reload();

        [, $toolCall, , $answer] = $this->store->loadAll('thread');
        $this->assertSame(
            [$toolCall->getId(), 'result_call_clock', 'reasoning_'.$answer->getId(), $answer->getId()],
            array_slice(array_column($reload['messages'], 'id'), 1),
        );
        $this->assertSame($this->first($live, 'TOOL_CALL_START')['parentMessageId'], $reload['messages'][1]['id']);
        $this->assertSame('call_clock', $reload['messages'][1]['toolCalls'][0]['id']);
        $this->assertSame($this->first($live, 'TOOL_CALL_RESULT')['messageId'], $reload['messages'][2]['id']);
        $this->assertSame([], $reload['interrupts']);
    }

    public function test_frontend_calls_awaiting_results_come_back_with_an_interrupt(): void
    {
        $provider = new FakeAIProvider(
            new ToolCallMessage(null, [new ToolCall('browser', 'call_a', deferred: true), new ToolCall('browser', 'call_b', deferred: true)]),
            new AssistantMessage('Finished'),
        );
        $tool = new FrontendTool('browser');
        $this->events($this->agent($provider, $tool)->stream(new UserMessage('Read the pages')));
        $this->agent($provider, $tool)->submitToolResults(['call_a' => ['result' => 'Page A']])->run();

        $reload = $this->reload();

        $this->assertSame(['call_a', 'call_b'], array_column($reload['messages'][1]['toolCalls'], 'id'));
        $this->assertSame(['id' => 'result_call_a', 'role' => 'tool', 'toolCallId' => 'call_a', 'content' => 'Page A'], $reload['messages'][2]);
        $interrupt = $reload['interrupts'][0];
        $this->assertSame('neuron:wait_for_event', $interrupt['reason']);
        $this->assertSame('tool_results', $interrupt['metadata']['eventName']);
        $this->assertSame(['call_b'], array_column($interrupt['metadata']['toolCalls'], 'callId'));

        $state = $this->agent($provider, $tool)->submitInputs(['resume' => [[
            'interruptId' => $interrupt['id'],
            'status' => 'resolved',
            'payload' => ['call_b' => ['result' => 'Page B']],
        ]]], new AGUIInputTranslator())->run();
        $this->assertSame('Finished', $state->getMessage()->getContent());
    }

    public function test_calls_of_an_answered_batch_wait_for_their_results(): void
    {
        $provider = new FakeAIProvider(new ToolCallMessage('Counting', [new ToolCall('count_users', 'call_count')]));
        $tool = (new ClosureDependencyTool(fn (): int => throw new RuntimeException('Database unavailable')))->requireApproval();
        $this->agent($provider, $tool)->chat(new UserMessage('How many users?'));
        try {
            $this->agent($provider, $tool)->submitApprovalDecisions(['call_count' => 'approve'])->run();
            $this->fail('The approved tool must fail the run.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Database unavailable', $exception->getMessage());
        }

        $reload = $this->reload();

        $this->assertSame([['role' => 'user', 'content' => 'How many users?'], ['role' => 'assistant', 'content' => 'Counting']], array_map(
            static fn (array $message): array => ['role' => $message['role'], 'content' => $message['content']],
            $reload['messages'],
        ));
        $this->assertArrayNotHasKey('toolCalls', $reload['messages'][1]);
        $this->assertSame([], $reload['interrupts']);
    }

    public function test_the_question_of_a_turn_without_an_answer_is_shown(): void
    {
        $question = new UserMessage('Hello');
        try {
            $this->agent(new FakeAIProvider(), new ToolStub('unused'))->chat($question);
            $this->fail('The empty provider must fail the first inference.');
        } catch (ProviderException) {
        }

        $reload = $this->reload();

        $this->assertSame([], $this->store->loadAll('thread'));
        $this->assertSame([['id' => $question->getId(), 'role' => 'user', 'content' => 'Hello']], $reload['messages']);
    }

    public function test_an_older_page_shows_every_call(): void
    {
        $failed = (new ToolCall('search', 'call_search'))->setResult(ToolOutput::error('Index offline'));
        $page = [
            new ToolResultMessage([$failed]),
            new AssistantMessage('Let me check the clock'),
            new ToolCallMessage(null, [new ToolCall('clock', 'call_clock', ['zone' => 'UTC'])]),
        ];

        $messages = (new AGUIAdapter('thread'))->hydrate($page, null)['messages'];

        $this->assertSame(
            ['id' => 'result_call_search', 'role' => 'tool', 'toolCallId' => 'call_search', 'content' => 'Index offline', 'error' => 'Index offline'],
            $messages[0],
        );
        $this->assertSame([[
            'id' => 'call_clock',
            'type' => 'function',
            'function' => ['name' => 'clock', 'arguments' => '{"zone":"UTC"}'],
        ]], $messages[2]['toolCalls']);
    }

    public function test_media_become_input_parts_and_text_stays_a_string(): void
    {
        $media = new UserMessage([
            new TextContent('Compare these'),
            new ImageContent('https://example.com/a.png', SourceType::URL, 'image/png'),
            new AudioContent('YXVkaW8=', SourceType::BASE64, 'audio/wav'),
            new FileContent('file-provider-id', SourceType::ID),
        ]);

        $messages = (new AGUIAdapter('thread'))->hydrate([$media, new UserMessage('Thanks')], null)['messages'];

        $this->assertSame([
            ['type' => 'text', 'text' => 'Compare these'],
            ['type' => 'image', 'source' => ['type' => 'url', 'value' => 'https://example.com/a.png', 'mimeType' => 'image/png']],
            ['type' => 'audio', 'source' => ['type' => 'data', 'value' => 'YXVkaW8=', 'mimeType' => 'audio/wav']],
        ], $messages[0]['content']);
        $this->assertSame('Thanks', $messages[1]['content']);
    }

    /** @return iterable<string, array{WorkflowStatus}> */
    public static function unsettledStatuses(): iterable
    {
        yield 'running' => [WorkflowStatus::Running];
        yield 'failed' => [WorkflowStatus::Failed];
        yield 'completed' => [WorkflowStatus::Completed];
    }

    #[DataProvider('unsettledStatuses')]
    public function test_only_a_suspended_run_returns_its_interrupts(WorkflowStatus $status): void
    {
        $approval = (new ApprovalRequest('Approve', [new Action('call_1', 'delete')]))->withId(1);

        $page = (new AGUIAdapter('thread'))->hydrate([], $this->snapshot($status, $approval));

        $this->assertSame([], $page['interrupts']);
    }

    public function test_a_suspended_approval_returns_one_confirmation_per_action(): void
    {
        $approval = (new ApprovalRequest('Approve', [new Action('call_1', 'delete'), new Action('call_2', 'send')]))->withId(1);

        $page = (new AGUIAdapter('thread'))->hydrate([], $this->snapshot(WorkflowStatus::Suspended, $approval));

        $this->assertSame(['call_1', 'call_2'], array_column($page['interrupts'], 'id'));
        $this->assertSame(['confirmation', 'confirmation'], array_column($page['interrupts'], 'reason'));
    }

    public function test_accepted_partial_results_keep_their_outcome(): void
    {
        $calls = [
            new ToolCall('browser', 'call_a', deferred: true),
            new ToolCall('browser', 'call_b', deferred: true),
            new ToolCall('browser', 'call_c', deferred: true),
        ];
        $waiting = (new ToolResultsRequest([$calls[2]], [
            'call_a' => ['error' => 'Tab closed'],
            'call_b' => ['result' => ['title' => 'Caffè']],
        ]))->withId(3);

        $page = (new AGUIAdapter('thread'))->hydrate(
            [new ToolCallMessage(null, $calls)],
            $this->snapshot(WorkflowStatus::Suspended, $waiting),
        );

        $this->assertSame(['call_a', 'call_b', 'call_c'], array_column($page['messages'][0]['toolCalls'], 'id'));
        $this->assertSame([
            ['id' => 'result_call_a', 'role' => 'tool', 'toolCallId' => 'call_a', 'content' => 'Tab closed', 'error' => 'Tab closed'],
            ['id' => 'result_call_b', 'role' => 'tool', 'toolCallId' => 'call_b', 'content' => '{"title":"Caff\u00e8"}'],
        ], array_slice($page['messages'], 1));
        $this->assertSame(['call_c'], array_column($page['interrupts'][0]['metadata']['toolCalls'], 'callId'));
    }

    public function test_calls_answered_on_the_latest_page_are_shown(): void
    {
        $call = new ToolCall('clock', 'call_clock');
        $message = new ToolCallMessage('Checking', [$call]);
        $result = new ToolResultMessage([(new ToolCall('clock', 'call_clock'))->setResult('ten')]);

        $page = (new AGUIAdapter('thread'))->hydrate([$message, $result], $this->snapshot(WorkflowStatus::Running, null));

        $this->assertSame(['call_clock'], array_column($page['messages'][0]['toolCalls'], 'id'));
        $this->assertSame(['id' => 'result_call_clock', 'role' => 'tool', 'toolCallId' => 'call_clock', 'content' => 'ten'], $page['messages'][1]);
    }

    public function test_calls_without_results_on_the_latest_page_are_held_back(): void
    {
        $message = new ToolCallMessage('Checking', [new ToolCall('clock', 'call_clock')]);

        $page = (new AGUIAdapter('thread'))->hydrate([$message], $this->snapshot(WorkflowStatus::Running, null));

        $this->assertSame([['id' => $message->getId(), 'role' => 'assistant', 'content' => 'Checking']], $page['messages']);
    }

    public function test_a_completed_run_does_not_repeat_its_input(): void
    {
        $question = new UserMessage('Hello');

        $page = (new AGUIAdapter('thread'))->hydrate([], $this->snapshot(WorkflowStatus::Completed, null, [$question]));

        $this->assertSame([], $page['messages']);
    }

    public function test_an_uncommitted_input_is_appended_only_once(): void
    {
        $question = new UserMessage('Hello');

        $page = (new AGUIAdapter('thread'))->hydrate([$question], $this->snapshot(WorkflowStatus::Failed, null, [$question]));

        $this->assertSame([$question->getId()], array_column($page['messages'], 'id'));
    }

    public function test_a_reasoning_only_message_has_no_empty_assistant_entry(): void
    {
        $message = new AssistantMessage([new ReasoningContent('Just thinking')]);

        $page = (new AGUIAdapter('thread'))->hydrate([$message], null);

        $this->assertSame([['id' => 'reasoning_' . $message->getId(), 'role' => 'reasoning', 'content' => 'Just thinking']], $page['messages']);
    }

    /** @return iterable<string, array{UserMessage, list<array<string, mixed>>}> */
    public static function mediaWithoutImages(): iterable
    {
        yield 'audio' => [
            new UserMessage([new TextContent('Listen'), new AudioContent('https://example.com/a.mp3', SourceType::URL)]),
            [['type' => 'text', 'text' => 'Listen'], ['type' => 'audio', 'source' => ['type' => 'url', 'value' => 'https://example.com/a.mp3']]],
        ];
        yield 'video' => [
            new UserMessage([new TextContent('Watch'), new VideoContent('https://example.com/a.mp4', SourceType::URL)]),
            [['type' => 'text', 'text' => 'Watch'], ['type' => 'video', 'source' => ['type' => 'url', 'value' => 'https://example.com/a.mp4']]],
        ];
        yield 'document' => [
            new UserMessage([new TextContent('Read'), new FileContent('cGRm', SourceType::BASE64, 'application/pdf')]),
            [['type' => 'text', 'text' => 'Read'], ['type' => 'document', 'source' => ['type' => 'data', 'value' => 'cGRm', 'mimeType' => 'application/pdf']]],
        ];
    }

    /**
     * @param list<array<string, mixed>> $parts
     */
    #[DataProvider('mediaWithoutImages')]
    public function test_any_media_block_turns_the_content_into_parts(UserMessage $message, array $parts): void
    {
        $page = (new AGUIAdapter('thread'))->hydrate([$message], null);

        $this->assertSame($parts, $page['messages'][0]['content']);
    }

    public function test_media_sources_fall_back_to_safe_mime_types(): void
    {
        $message = new UserMessage([
            new ImageContent('https://example.com/a', SourceType::URL),
            new VideoContent('dmlkZW8=', SourceType::BASE64),
            new FileContent('https://example.com/a.pdf', SourceType::URL, 'application/pdf'),
        ]);

        $page = (new AGUIAdapter('thread'))->hydrate([$message], null);

        $this->assertSame([
            ['type' => 'image', 'source' => ['type' => 'url', 'value' => 'https://example.com/a']],
            ['type' => 'video', 'source' => ['type' => 'data', 'value' => 'dmlkZW8=', 'mimeType' => 'application/octet-stream']],
            ['type' => 'document', 'source' => ['type' => 'url', 'value' => 'https://example.com/a.pdf', 'mimeType' => 'application/pdf']],
        ], $page['messages'][0]['content']);
    }

    public function test_hydration_leaves_the_live_stream_state_untouched(): void
    {
        $adapter = new AGUIAdapter('thread', 'run');
        $adapter->hydrate([new ToolCallMessage(null, [new ToolCall('clock', 'call_clock')])], null);

        $events = iterator_to_array($adapter->transform(new ToolResultChunk((new ToolCall('clock', 'call_clock'))->setResult('ten'))), false);

        $this->assertSame(
            ['TOOL_CALL_START', 'TOOL_CALL_ARGS', 'TOOL_CALL_END', 'TOOL_CALL_RESULT'],
            array_map(static fn (ProtocolEvent $event): string => $event->type, $events),
        );
    }

    /**
     * @param Message[] $input
     */
    protected function snapshot(WorkflowStatus $status, ?InterruptRequest $interrupt, array $input = []): WorkflowRunSnapshot
    {
        return new WorkflowRunSnapshot('run-1', $status, 1, $interrupt, 'thread', new AgentStartEvent($input));
    }

    protected function agent(FakeAIProvider $provider, ToolInterface $tool): Agent
    {
        $agent = Agent::make(workflowId: 'thread');
        $agent->setMessageStore($this->store)->setPersistence($this->persistence)->setAiProvider($provider);
        $agent->addTool($tool);
        $agent->setStreamAdapter(fn (): AGUIAdapter => new AGUIAdapter('thread'));

        return $agent;
    }

    /**
     * A page reload: the latest stored messages and a fresh read of the run.
     *
     * @return array{messages: list<array<string, mixed>>, interrupts: list<array<string, mixed>>}
     */
    protected function reload(): array
    {
        $run = (new WorkflowEngine($this->persistence))->inspect('thread');

        return (new AGUIAdapter('thread'))->hydrate($this->store->loadAll('thread'), $run);
    }

    /**
     * @param iterable<ProtocolEvent> $frames
     * @return list<array<string, mixed>>
     */
    protected function events(iterable $frames): array
    {
        $events = [];
        foreach ($frames as $frame) {
            $events[] = ['type' => $frame->type, ...$frame->data];
        }

        return $events;
    }

    /**
     * @param list<array<string, mixed>> $events
     * @return array<string, mixed>
     */
    protected function first(array $events, string $type): array
    {
        return array_values(array_filter($events, static fn (array $event): bool => $event['type'] === $type))[0];
    }

    protected function json(mixed $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR);
    }
}
