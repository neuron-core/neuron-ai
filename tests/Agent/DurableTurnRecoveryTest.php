<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Agent;

use NeuronAI\Agent\Agent;
use NeuronAI\Chat\History\FileChatHistory;
use NeuronAI\Chat\History\InMemoryChatHistory;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Exceptions\ProviderException;
use NeuronAI\Exceptions\RunInFlightException;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Tests\Agent\Stub\CountingTool;
use NeuronAI\Tests\Agent\Stub\SearchTool;
use NeuronAI\Tools\ToolCall;
use NeuronAI\Workflow\Interrupt\ApprovalRequest;
use NeuronAI\Workflow\Interrupt\ResumeInput;
use NeuronAI\Workflow\Persistence\FilePersistence;
use NeuronAI\Workflow\Persistence\InMemoryPersistence;
use NeuronAI\Workflow\Persistence\PersistenceInterface;
use NeuronAI\Workflow\WorkflowStatus;
use PHPUnit\Framework\TestCase;

use function array_map;
use function glob;
use function is_dir;
use function mkdir;
use function rmdir;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

use const DIRECTORY_SEPARATOR;

/**
 * The two ways a durable thread moves on after a failed turn: run([]) replays
 * the failed generation reusing every committed step, and a new chat()
 * supersedes it with whatever message the user sends next. The file-backed
 * cases use blank Agent instances per step, mirroring separate processes.
 */
class DurableTurnRecoveryTest extends TestCase
{
    protected string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'neuron_durable_' . uniqid();
        mkdir($this->directory);
        CountingTool::reset();
    }

    protected function tearDown(): void
    {
        if (is_dir($this->directory)) {
            array_map(unlink(...), glob($this->directory . DIRECTORY_SEPARATOR . '*') ?: []);
            rmdir($this->directory);
        }
    }

    /**
     * @return string[]
     */
    protected function contents(array $messages): array
    {
        return array_map(
            static fn (Message $message): string => (string) $message->getContent(),
            $messages,
        );
    }

    protected function makeAgent(
        FakeAIProvider $provider,
        InMemoryChatHistory $history,
        PersistenceInterface $persistence,
        ?CountingTool $tool = null,
    ): Agent {
        $agent = Agent::make();
        $agent->setChatHistory($history);
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
     */
    protected function fileAgent(FakeAIProvider $provider, ?SearchTool $tool = null): Agent
    {
        $agent = Agent::make(threadId: 'thread-file');
        $agent->setChatHistory(new FileChatHistory($this->directory));
        $agent->setAiProvider($provider);
        $agent->setPersistence(new FilePersistence($this->directory));
        if ($tool instanceof SearchTool) {
            $agent->addTool($tool);
        }

        return $agent;
    }

    public function test_replay_after_a_failed_follow_up_reuses_the_first_inference_and_the_tool_run(): void
    {
        $tool = new CountingTool();
        $history = new InMemoryChatHistory();
        $persistence = new InMemoryPersistence();
        // The first inference asks for the tool; the follow-up inference has
        // nothing queued and fails.
        $provider = new FakeAIProvider(
            new ToolCallMessage(null, [ToolCall::make($tool->getName(), 'call_1', ['query' => 'PHP frameworks'])]),
        );

        try {
            $this->makeAgent($provider, $history, $persistence, $tool)->chat(new UserMessage('Search for PHP frameworks'));
            $this->fail('Expected the follow-up provider failure to propagate.');
        } catch (ProviderException) {
        }

        // Committed: the first inference and the tool run. The failed
        // follow-up left the history tail at the user message.
        $this->assertSame(1, $provider->getCallCount());
        $this->assertSame(1, CountingTool::$executions);
        $this->assertSame(['Search for PHP frameworks'], $this->contents($history->getMessages()));

        $provider->addResponses(new AssistantMessage('Laravel and Symfony.'));
        $state = $this->makeAgent($provider, $history, $persistence, $tool)->run([]);

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

        $this->assertCount(4, $history->getMessages());
        $this->assertNull($persistence->get((string) $history->getThreadId(), '__control'));
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
            $this->contents((new FileChatHistory($this->directory, 'thread-file'))->getMessages()),
        );
        $this->assertNull((new FilePersistence($this->directory))->get('thread-file', '__control'));
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
            $this->assertSame($suspended->getRunId(), $e->runId);
            $this->assertStringContainsString("wait_for_event 'approval'", $e->getMessage());
        }

        // Process 3: the approval is delivered from a cold start and the run completes.
        $message = $this->fileAgent($provider, $tool)
            ->run([ResumeInput::event((new ApprovalRequest('test'))->withId(1), ['call_1' => 'approve'])])
            ->getMessage();

        $this->assertSame('Here are the search results...', $message->getContent());
        $this->assertNull((new FilePersistence($this->directory))->get('thread-file', '__control'));
    }
}
