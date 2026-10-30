<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Agent;

use NeuronAI\Agent\Agent;
use NeuronAI\Chat\History\ChatHistory;
use NeuronAI\Chat\History\FileMessageStore;
use NeuronAI\Chat\History\InMemoryMessageStore;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Exceptions\ProviderException;
use NeuronAI\Exceptions\RunInFlightException;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Tests\Agent\Stub\CountingStartAgent;
use NeuronAI\Tests\Agent\Stub\CountingStartNode;
use NeuronAI\Tests\Agent\Stub\CountingTool;
use NeuronAI\Tests\Agent\Stub\CrashSearchTool;
use NeuronAI\Tests\Agent\Stub\FailingOnceMessageStore;
use NeuronAI\Tests\Agent\Stub\ItalianAgent;
use NeuronAI\Tests\Agent\Stub\ItalianStartNode;
use NeuronAI\Tests\Agent\Stub\SearchTool;
use NeuronAI\Tests\StructuredOutput\Stub\User;
use NeuronAI\Tools\ToolCall;
use NeuronAI\Tools\ToolInterface;
use NeuronAI\Tools\ToolOutput;
use NeuronAI\Workflow\Executor\ExecutionRequest;
use NeuronAI\Workflow\Persistence\FilePersistence;
use NeuronAI\Workflow\Persistence\InMemoryPersistence;
use NeuronAI\Workflow\Persistence\PersistenceInterface;
use NeuronAI\Workflow\WorkflowStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Generator;
use RuntimeException;

use function array_map;
use function get_class;
use function glob;
use function implode;
use function is_dir;
use function mkdir;
use function rmdir;
use function str_repeat;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;
use function in_array;
use function iterator_to_array;

use const DIRECTORY_SEPARATOR;

/**
 * The two ways a durable thread moves on after a failed turn: run([]) replays
 * the failed generation reusing every committed step, and a new chat()
 * supersedes it with whatever message the user sends next. The file-backed
 * cases use blank Agent instances per step, mirroring separate processes.
 */
class DurableTurnRecoveryTest extends TestCase
{
    protected const INTERRUPTED = 'My previous response was interrupted before it was completed.';

    /** The tool exchange of a turn whose follow-up inference failed. */
    protected const EXCHANGE = ['call lookup', 'result Results for: PHP frameworks'];

    protected string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'neuron_durable_' . uniqid();
        mkdir($this->directory);
        CountingTool::reset();
        CountingStartNode::$calls = 0;
    }

    protected function tearDown(): void
    {
        if (is_dir($this->directory)) {
            array_map(unlink(...), glob($this->directory . DIRECTORY_SEPARATOR . '*') ?: []);
            rmdir($this->directory);
        }
    }

    /**
     * What each message says: its text, or the tools it calls or answers.
     *
     * @param Message[] $messages
     * @return string[]
     */
    protected function contents(array $messages): array
    {
        return array_map(fn (Message $message): string => match (true) {
            $message instanceof ToolResultMessage => 'result ' . implode(', ', array_map($this->resultText(...), $message->getToolCalls())),
            $message instanceof ToolCallMessage => 'call ' . implode(', ', array_map(static fn (ToolCall $call): string => $call->getName(), $message->getToolCalls())),
            default => (string) $message->getContent(),
        }, $messages);
    }

    protected function resultText(ToolCall $call): string
    {
        $result = $call->getResult();

        return $result instanceof ToolOutput ? $result->getText() : $result;
    }

    /**
     * @param class-string<Agent> $class
     */
    protected function makeAgent(
        FakeAIProvider $provider,
        InMemoryMessageStore $messageStore,
        PersistenceInterface $persistence,
        ?CountingTool $tool = null,
        string $class = Agent::class,
    ): Agent {
        $agent = $class::make(workflowId: 'thread');
        $agent->setMessageStore($messageStore);
        $agent->setAiProvider($provider);
        $agent->setPersistence($persistence);
        if ($tool instanceof CountingTool) {
            $agent->addTool($tool);
        }

        return $agent;
    }

    /**
     * A blank, file-backed instance: nothing but the thread ID and the
     * directory is shared with the previous "process".
     *
     * @param class-string<Agent> $class
     */
    protected function fileAgent(FakeAIProvider $provider, ?ToolInterface $tool = null, string $class = Agent::class): Agent
    {
        $agent = $class::make(workflowId: 'thread-file');
        $agent->setMessageStore(new FileMessageStore($this->directory));
        $agent->setAiProvider($provider);
        $agent->setPersistence(new FilePersistence($this->directory));
        if ($tool instanceof ToolInterface) {
            $agent->addTool($tool);
        }

        return $agent;
    }

    /**
     * @return Message[]
     */
    protected function fileHistory(): array
    {
        return (new ChatHistory(new FileMessageStore($this->directory), 'thread-file'))->getMessages();
    }

    /**
     * The first inference asks for the tool, the tool runs, and the follow-up
     * inference fails: the history holds the question and the tool exchange,
     * and no answer.
     */
    protected function failTheFollowUp(
        FakeAIProvider $provider,
        InMemoryMessageStore $messageStore,
        PersistenceInterface $persistence,
        CountingTool $tool,
        UserMessage $question = new UserMessage('Search for PHP frameworks'),
    ): void {
        $provider->addResponses(
            new ToolCallMessage(null, [ToolCall::make($tool->getName(), 'call_1', ['query' => 'PHP frameworks'])]),
        );

        try {
            $this->makeAgent($provider, $messageStore, $persistence, $tool)->chat($question);
            $this->fail('Expected the follow-up provider failure to propagate.');
        } catch (ProviderException) {
        }

        $this->assertSame([$question->getContent(), ...self::EXCHANGE], $this->contents($messageStore->loadActive('thread')));
    }

    /**
     * The tool throws: its call was written before it ran, so the history ends
     * with the call and no result.
     *
     * @param class-string<Agent> $class
     */
    protected function leaveTheCallOpen(FakeAIProvider $provider, CrashSearchTool $tool, string $class = Agent::class): ToolCall
    {
        $call = ToolCall::make($tool->getName(), 'call_1', ['query' => 'PHP frameworks']);
        $provider->addResponses(new ToolCallMessage(null, [$call]));

        try {
            $this->fileAgent($provider, $tool, $class)->chat(new UserMessage('Search for PHP frameworks'));
            $this->fail('Expected the tool failure to propagate.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Simulated crash', $exception->getMessage());
        }

        $this->assertSame(['Search for PHP frameworks', 'call search'], $this->contents($this->fileHistory()));

        return $call;
    }

    /** @return iterable<string, array{string}> */
    public static function newTurnMethods(): iterable
    {
        foreach (['chat', 'stream', 'structured'] as $method) {
            yield $method => [$method];
        }
    }

    #[DataProvider('newTurnMethods')]
    public function test_a_new_turn_keeps_the_tool_exchange_of_a_failed_follow_up_and_closes_its_turn(string $method): void
    {
        $tool = new CountingTool();
        $messageStore = new InMemoryMessageStore();
        $persistence = new InMemoryPersistence();
        $provider = new FakeAIProvider();
        $this->failTheFollowUp($provider, $messageStore, $persistence, $tool);

        $provider->addResponses(new AssistantMessage('{"name":"Laravel"}'));
        $agent = $this->makeAgent($provider, $messageStore, $persistence, $tool);
        $arguments = $method === 'structured'
            ? [new UserMessage('Name one'), User::class]
            : [new UserMessage('Name one')];
        $result = $agent->$method(...$arguments);
        if ($result instanceof Generator) {
            iterator_to_array($result);
        }

        // The model sees what the tool returned, then the note and the new question.
        $closed = ['Search for PHP frameworks', ...self::EXCHANGE, self::INTERRUPTED, 'Name one'];
        $this->assertSame($closed, $this->contents($provider->getRecorded()[1]->messages));
        $this->assertSame([...$closed, '{"name":"Laravel"}'], $this->contents($messageStore->loadActive('thread')));
        $this->assertSame(1, CountingTool::$executions);
    }

    public function test_a_new_turn_that_fails_at_once_leaves_the_turn_open_and_its_recovery_closes_it_once(): void
    {
        $tool = new CountingTool();
        $messageStore = new InMemoryMessageStore();
        $persistence = new InMemoryPersistence();
        $provider = new FakeAIProvider();
        $this->failTheFollowUp($provider, $messageStore, $persistence, $tool);

        try {
            $this->makeAgent($provider, $messageStore, $persistence, $tool)->chat(new UserMessage('Name one'));
            $this->fail('Expected the provider failure to propagate.');
        } catch (ProviderException) {
        }

        // The closing messages commit with the new question, so a first inference
        // that fails writes neither.
        $this->assertSame(['Search for PHP frameworks', ...self::EXCHANGE], $this->contents($messageStore->loadActive('thread')));

        $provider->addResponses(new AssistantMessage('Laravel.'));
        $this->makeAgent($provider, $messageStore, $persistence, $tool)->run();

        $this->assertSame(
            ['Search for PHP frameworks', ...self::EXCHANGE, self::INTERRUPTED, 'Name one', 'Laravel.'],
            $this->contents($messageStore->loadActive('thread')),
        );
    }

    public function test_a_turn_without_messages_answers_the_open_question(): void
    {
        $tool = new CountingTool();
        $messageStore = new InMemoryMessageStore();
        $persistence = new InMemoryPersistence();
        $provider = new FakeAIProvider();
        $this->failTheFollowUp($provider, $messageStore, $persistence, $tool);

        $provider->addResponses(new AssistantMessage('Laravel and Symfony.'));
        $this->makeAgent($provider, $messageStore, $persistence, $tool)->chat();

        $this->assertSame(['Search for PHP frameworks', ...self::EXCHANGE], $this->contents($provider->getRecorded()[1]->messages));
        $this->assertSame(
            ['Search for PHP frameworks', ...self::EXCHANGE, 'Laravel and Symfony.'],
            $this->contents($messageStore->loadActive('thread')),
        );
    }

    public function test_retrying_the_open_question_answers_it_without_a_note(): void
    {
        $tool = new CountingTool();
        $messageStore = new InMemoryMessageStore();
        $persistence = new InMemoryPersistence();
        $provider = new FakeAIProvider();
        $question = new UserMessage('Search for PHP frameworks');
        $this->failTheFollowUp($provider, $messageStore, $persistence, $tool, $question);

        $provider->addResponses(new AssistantMessage('Laravel and Symfony.'));
        $this->makeAgent($provider, $messageStore, $persistence, $tool)->chat($question);

        $this->assertSame(['Search for PHP frameworks', ...self::EXCHANGE], $this->contents($provider->getRecorded()[1]->messages));
        $this->assertSame(
            ['Search for PHP frameworks', ...self::EXCHANGE, 'Laravel and Symfony.'],
            $this->contents($messageStore->loadActive('thread')),
        );
    }

    public function test_recovery_writes_once_the_closing_messages_the_trim_archived(): void
    {
        $tool = new CountingTool();
        $messageStore = new FailingOnceMessageStore('Laravel.');
        $persistence = new InMemoryPersistence();
        $provider = new FakeAIProvider();
        $question = new UserMessage('Search for PHP frameworks ' . str_repeat('lorem ipsum dolor sit amet ', 100));
        $this->failTheFollowUp($provider, $messageStore, $persistence, $tool, $question);

        // The commit of the note and the new question overflows the window, so the
        // trim archives the open turn with its note; then the answer write fails.
        $provider->addResponses(new AssistantMessage('Laravel.'));
        try {
            $this->makeAgent($provider, $messageStore, $persistence, $tool)->setContextWindow(300)->chat(new UserMessage('Name one'));
            $this->fail('Expected the store failure to propagate.');
        } catch (RuntimeException) {
        }
        $this->assertSame(['Name one'], $this->contents($messageStore->loadActive('thread')));

        $this->makeAgent($provider, $messageStore, $persistence, $tool)->setContextWindow(300)->run();

        $this->assertSame(['Name one', 'Laravel.'], $this->contents($messageStore->loadActive('thread')));
        $this->assertSame(
            [$question->getContent(), ...self::EXCHANGE, self::INTERRUPTED, 'Name one', 'Laravel.'],
            $this->contents($messageStore->loadAll('thread')),
        );
    }

    public function test_a_tool_result_sent_as_the_new_turn_answers_the_open_call(): void
    {
        $tool = new CrashSearchTool();
        $provider = new FakeAIProvider();
        $call = $this->leaveTheCallOpen($provider, $tool);

        $provider->addResponses(new AssistantMessage('Laravel and Symfony.'));
        $this->fileAgent($provider, $tool)->chat(new ToolResultMessage([(clone $call)->setResult('Laravel, Symfony')]));

        $this->assertSame(
            ['Search for PHP frameworks', 'call search', 'result Laravel, Symfony', 'Laravel and Symfony.'],
            $this->contents($this->fileHistory()),
        );
    }

    public function test_a_tool_result_and_a_question_sent_as_the_new_turn_answer_the_open_call_before_the_question(): void
    {
        $tool = new CrashSearchTool();
        $provider = new FakeAIProvider();
        $call = $this->leaveTheCallOpen($provider, $tool);

        $provider->addResponses(new AssistantMessage('Symfony.'));
        $this->fileAgent($provider, $tool)->chat([
            new ToolResultMessage([(clone $call)->setResult('Laravel, Symfony')]),
            new UserMessage('Which one is older?'),
        ]);

        $this->assertSame(
            ['Search for PHP frameworks', 'call search', 'result Laravel, Symfony', self::INTERRUPTED, 'Which one is older?', 'Symfony.'],
            $this->contents($this->fileHistory()),
        );
    }

    public function test_an_entry_node_subclass_changes_what_the_closing_says(): void
    {
        $tool = new CrashSearchTool();
        $provider = new FakeAIProvider();
        $this->leaveTheCallOpen($provider, $tool, ItalianAgent::class);

        $provider->addResponses(new AssistantMessage('Non posso confermarlo.'));
        $this->fileAgent($provider, $tool, ItalianAgent::class)->chat(new UserMessage('Ha funzionato?'));

        $stored = $this->fileHistory();
        $this->assertSame(
            ['Search for PHP frameworks', 'call search', 'result ' . ItalianStartNode::INTERRUPTED_RESULT, ItalianStartNode::INTERRUPTED_ANSWER, 'Ha funzionato?', 'Non posso confermarlo.'],
            $this->contents($stored),
        );
        $answer = $stored[2];
        $this->assertInstanceOf(ToolResultMessage::class, $answer);
        $result = $answer->getToolCalls()[0]->getResult();
        $this->assertInstanceOf(ToolOutput::class, $result);
        $this->assertTrue($result->isError());
    }

    public function test_a_recovered_run_reuses_the_messages_its_entry_node_returned(): void
    {
        $tool = new CountingTool();
        $messageStore = new InMemoryMessageStore();
        $persistence = new InMemoryPersistence();
        $provider = new FakeAIProvider();
        $this->failTheFollowUp($provider, $messageStore, $persistence, $tool);

        try {
            $this->makeAgent($provider, $messageStore, $persistence, $tool, CountingStartAgent::class)->chat(new UserMessage('Name one'));
            $this->fail('Expected the provider failure to propagate.');
        } catch (ProviderException) {
        }

        $provider->addResponses(new AssistantMessage('Laravel.'));
        $this->makeAgent($provider, $messageStore, $persistence, $tool, CountingStartAgent::class)->run();

        $this->assertSame(1, CountingStartNode::$calls);
        $this->assertSame(
            ['Search for PHP frameworks', ...self::EXCHANGE, self::INTERRUPTED, 'Name one', 'Laravel.'],
            $this->contents($messageStore->loadActive('thread')),
        );
    }

    public function test_a_new_turn_answers_the_tool_call_a_failed_approved_turn_left_open(): void
    {
        $tool = new CrashSearchTool();
        $tool->requireApproval();
        $provider = new FakeAIProvider(
            new ToolCallMessage(null, [ToolCall::make($tool->getName(), 'call_1', ['query' => 'PHP frameworks'])]),
        );

        // Process 1 pauses on the approval. Process 2 approves it and the tool
        // throws: its call was written before the suspend, its result never was.
        $this->assertTrue($this->fileAgent($provider, $tool)->chat(new UserMessage('Search for PHP frameworks'))->isInterrupted());
        try {
            $this->fileAgent($provider, $tool)->run(ExecutionRequest::resume(['call_1' => 'approve']));
            $this->fail('Expected the tool failure to propagate.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Simulated crash', $exception->getMessage());
        }

        // Process 3: the user moves on.
        $provider->addResponses(new AssistantMessage('I could not confirm the search.'));
        $this->fileAgent($provider, $tool)->chat(new UserMessage('Did it work?'));

        $stored = $this->fileHistory();
        $this->assertSame(
            [UserMessage::class, ToolCallMessage::class, ToolResultMessage::class, AssistantMessage::class, UserMessage::class, AssistantMessage::class],
            array_map(get_class(...), $stored),
        );
        $this->assertSame([self::INTERRUPTED, 'Did it work?'], $this->contents([$stored[3], $stored[4]]));

        // The stored call does not say whether it ran, so its result claims neither.
        $answer = $stored[2];
        $this->assertInstanceOf(ToolResultMessage::class, $answer);
        $call = $answer->getToolCalls()[0];
        $this->assertSame('call_1', $call->getCallId());
        $this->assertNull($call->getApprovalState());
        $result = $call->getResult();
        $this->assertInstanceOf(ToolOutput::class, $result);
        $this->assertTrue($result->isError());
        $this->assertSame(
            'The call was interrupted before its result was recorded: it may or may not have taken effect.',
            $result->getText(),
        );
    }

    public function test_replay_after_a_failed_follow_up_reuses_the_first_inference_and_the_tool_run(): void
    {
        $tool = new CountingTool();
        $messageStore = new InMemoryMessageStore();
        $persistence = new InMemoryPersistence();
        // The first inference asks for the tool; the follow-up inference has
        // nothing queued and fails.
        $provider = new FakeAIProvider(
            new ToolCallMessage(null, [ToolCall::make($tool->getName(), 'call_1', ['query' => 'PHP frameworks'])]),
        );

        try {
            $this->makeAgent($provider, $messageStore, $persistence, $tool)->chat(new UserMessage('Search for PHP frameworks'));
            $this->fail('Expected the follow-up provider failure to propagate.');
        } catch (ProviderException) {
        }

        // Committed: the first inference and the tool run, whose exchange is in
        // history. The follow-up failed before answering.
        $this->assertSame(1, $provider->getCallCount());
        $this->assertSame(1, CountingTool::$executions);
        $this->assertSame(['Search for PHP frameworks', ...self::EXCHANGE], $this->contents($messageStore->loadActive('thread')));

        $provider->addResponses(new AssistantMessage('Laravel and Symfony.'));
        $state = $this->makeAgent($provider, $messageStore, $persistence, $tool)->run();

        // Only the failed step ran again: the first inference and the tool
        // were recalled, and the follow-up carried the original message plus
        // the memoized tool exchange.
        $this->assertSame(WorkflowStatus::Completed, $state->getStatus());
        $this->assertSame('Laravel and Symfony.', $state->getMessage()->getContent());
        $this->assertSame(2, $provider->getCallCount());
        $this->assertSame(1, CountingTool::$executions);

        $replayed = $provider->getRecorded()[1]->messages;
        $this->assertCount(3, $replayed);
        $this->assertSame('Search for PHP frameworks', $replayed[0]->getContent());
        $this->assertInstanceOf(ToolCallMessage::class, $replayed[1]);
        $this->assertInstanceOf(ToolResultMessage::class, $replayed[2]);

        $this->assertCount(4, $messageStore->loadActive('thread'));
        $this->assertNull($persistence->get('thread', '__control'));
    }

    public function test_blank_file_backed_instances_supersede_a_failed_turn_with_a_new_message(): void
    {
        $provider = new FakeAIProvider();

        // Process 1: the provider is down.
        try {
            $this->fileAgent($provider)->chat(new UserMessage('first message'));
            $this->fail('Expected the provider failure to propagate.');
        } catch (ProviderException) {
        }
        $this->assertNotNull((new FilePersistence($this->directory))->get('thread-file', '__control'));

        // Process 2: the provider is back and the user sends something else.
        $provider->addResponses(new AssistantMessage('reply'));
        $state = $this->fileAgent($provider)->chat(new UserMessage('second message'));

        $this->assertSame('reply', $state->getMessage()->getContent());
        $this->assertSame(1, $provider->getCallCount());
        $this->assertSame(['second message'], $this->contents($provider->getRecorded()[0]->messages));

        // Process 3: reads the conversation cold. The failed turn left no
        // trace, and the thread is free.
        $this->assertSame(
            ['second message', 'reply'],
            $this->contents((new ChatHistory(new FileMessageStore($this->directory), 'thread-file'))->getMessages()),
        );
        $this->assertNull((new FilePersistence($this->directory))->get('thread-file', '__control'));
    }

    /** @return iterable<string, array{string}> */
    public static function executionMethods(): iterable
    {
        foreach (['chat', 'stream', 'structured', 'run', 'events'] as $method) {
            yield $method => [$method];
        }
    }

    #[DataProvider('executionMethods')]
    public function test_new_turn_methods_preserve_new_messages_and_plain_terminals_recover(string $method): void
    {
        $provider = new FakeAIProvider();
        $failed = $this->fileAgent($provider);
        $failedRecord = new \NeuronAI\Tests\Support\ExecutionRecorder($failed);
        try {
            $failed->chat(new UserMessage('first message'));
            $this->fail('Expected the provider failure.');
        } catch (ProviderException) {
        }
        $provider->addResponses(new AssistantMessage('{"name":"Recovered"}'));
        $agent = $this->fileAgent($provider);
        $agentRecord = new \NeuronAI\Tests\Support\ExecutionRecorder($agent);
        $newTurn = in_array($method, ['chat', 'stream', 'structured'], true);
        $arguments = $newTurn ? [new UserMessage('second message')] : [];
        if ($method === 'structured') {
            $arguments[] = User::class;
        }
        $result = $agent->$method(...$arguments);
        if ($result instanceof Generator) {
            iterator_to_array($result);
        }
        $this->assertSame(
            [$newTurn ? 'second message' : 'first message'],
            $this->contents($provider->getRecorded()[0]->messages),
        );
        if ($newTurn) {
            $this->assertNotSame($failedRecord->context?->runId, $agentRecord->context?->runId);
        } else {
            $this->assertSame($failedRecord->context?->runId, $agentRecord->context?->runId);
        }
    }

    public function test_blank_file_backed_instances_keep_a_pending_approval_locked_until_settled(): void
    {
        $tool = new SearchTool();
        $tool->requireApproval();
        $provider = new FakeAIProvider(
            new ToolCallMessage(null, [ToolCall::make($tool->getName(), 'call_1', ['query' => 'PHP frameworks'])]),
            new AssistantMessage('Here are the search results...'),
        );

        // Process 1: the turn pauses on the approval.
        $suspended = $this->fileAgent($provider, $tool);
        $this->assertTrue($suspended->chat(new UserMessage('Search for PHP frameworks'))->isInterrupted());

        // Process 2: a new turn is refused, and the refusal names the wait.
        try {
            $this->fileAgent($provider, $tool)->chat(new UserMessage('Never mind'));
            $this->fail('A pending approval should refuse a new turn.');
        } catch (RunInFlightException $e) {
            $this->assertSame($suspended->inspect()?->runId, $e->runId);
            $this->assertStringContainsString("wait_for_event 'approval'", $e->getMessage());
        }

        // Process 3: the approval is delivered from a cold start and the run completes.
        $message = $this->fileAgent($provider, $tool)->run(\NeuronAI\Workflow\Executor\ExecutionRequest::resume(['call_1' => 'approve']))
            ->getMessage();

        $this->assertSame('Here are the search results...', $message->getContent());
        $this->assertNull((new FilePersistence($this->directory))->get('thread-file', '__control'));
    }
}
