<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Evaluation\Conversation;

use Closure;
use Illuminate\Database\Capsule\Manager as Capsule;
use NeuronAI\Agent\Agent;
use NeuronAI\Chat\History\ChatHistory;
use NeuronAI\Chat\History\EloquentMessageStore;
use NeuronAI\Chat\History\FileMessageStore;
use NeuronAI\Chat\History\InMemoryMessageStore;
use NeuronAI\Chat\History\MessageStoreInterface;
use NeuronAI\Chat\History\SQLMessageStore;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Evaluation\Assertions\Trajectory\ToolWasApproved;
use NeuronAI\Evaluation\Assertions\Trajectory\ToolWasCalled;
use NeuronAI\Evaluation\Assertions\Trajectory\ToolWasRejected;
use NeuronAI\Evaluation\Conversation\Trajectory;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Tests\Agent\Stub\CountingTool;
use NeuronAI\Tests\Chat\History\Stub\ChatMessage;
use NeuronAI\Tests\Chat\History\Stub\SqliteMessageStore;
use NeuronAI\Tools\ApprovalState;
use NeuronAI\Tools\ToolCall;
use NeuronAI\Tools\ToolInterface;
use NeuronAI\Workflow\Persistence\InMemoryPersistence;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function array_map;
use function bin2hex;
use function glob;
use function is_dir;
use function is_file;
use function random_bytes;
use function rmdir;
use function sys_get_temp_dir;
use function unlink;

/**
 * Evaluation reads what the Agent wrote: a Trajectory rebuilt from a stored
 * conversation must fold the approval snapshot the Agent writes before a
 * pause and the outcome it writes after the decisions, whichever message
 * store kept them, so trajectory assertions judge the real run.
 */
class StoredTrajectoryContractTest extends TestCase
{
    protected const THREAD = 'evaluated-thread';

    protected string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/neuron_trajectory_contract_'.bin2hex(random_bytes(6));
        CountingTool::reset();
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory.'/*') ?: [] as $file) {
            unlink($file);
        }
        if (is_dir($this->directory)) {
            rmdir($this->directory);
        }
        if (is_file($this->directory.'.sqlite')) {
            unlink($this->directory.'.sqlite');
        }
        CountingTool::reset();
    }

    /**
     * Each factory prepares a store backend and returns how a new process
     * connects to it.
     *
     * @return array<string, array{Closure(string): (Closure(): MessageStoreInterface)}>
     */
    public static function stores(): array
    {
        return [
            'in-memory' => [static function (string $directory): Closure {
                $store = new InMemoryMessageStore();

                return static fn (): MessageStoreInterface => $store;
            }],
            'file' => [static fn (string $directory): Closure => static fn (): MessageStoreInterface => new FileMessageStore($directory)],
            'sql' => [static function (string $directory): Closure {
                (new PDO('sqlite:'.$directory.'.sqlite'))->exec(SqliteMessageStore::SCHEMA);

                return static fn (): MessageStoreInterface => new SQLMessageStore(new PDO('sqlite:'.$directory.'.sqlite'));
            }],
            'eloquent' => [static function (string $directory): Closure {
                $capsule = new Capsule();
                $capsule->addConnection(['driver' => 'sqlite', 'database' => $directory.'.sqlite']);
                $capsule->setAsGlobal();
                $capsule->bootEloquent();
                (new PDO('sqlite:'.$directory.'.sqlite'))->exec(SqliteMessageStore::SCHEMA);

                return static fn (): MessageStoreInterface => new EloquentMessageStore(ChatMessage::class);
            }],
        ];
    }

    /**
     * @param Closure(string): (Closure(): MessageStoreInterface) $prepareStore
     */
    #[DataProvider('stores')]
    public function test_a_trajectory_rebuilt_from_the_store_reports_the_pause_and_the_decisions(Closure $prepareStore): void
    {
        $connect = $prepareStore($this->directory);
        $persistence = new InMemoryPersistence();

        $this->agent(new FakeAIProvider(new ToolCallMessage(null, [
            ToolCall::make('lookup', 'call_1', ['query' => 'php']),
            ToolCall::make('lookup', 'call_2', ['query' => 'rust']),
        ])), $connect(), $persistence)->chat(new UserMessage('Compare php and rust'));

        $paused = $this->trajectory($connect());
        $this->assertSame(
            [['call_1', ApprovalState::Pending, 'Lookups cost money', false], ['call_2', ApprovalState::Pending, 'Lookups cost money', false]],
            $this->describe($paused),
        );
        $this->assertTrue((new ToolWasCalled('lookup'))->evaluate($paused)->passed);
        $this->assertFalse((new ToolWasApproved('lookup'))->evaluate($paused)->passed);

        $this->agent(new FakeAIProvider(new AssistantMessage('PHP found, Rust skipped.')), $connect(), $persistence)
            ->submitApprovalDecisions(['call_1' => 'approve', 'call_2' => ['reject', 'Not needed']])
            ->run();

        $settled = $this->trajectory($connect());
        $this->assertSame(
            [['call_1', ApprovalState::Approved, 'Lookups cost money', true], ['call_2', ApprovalState::Rejected, 'Lookups cost money', true]],
            $this->describe($settled),
        );
        $this->assertSame('Results for: php', (string) $settled->toolCalls()[0]->getResult());
        $this->assertSame('Not needed', $settled->toolCalls()[1]->getRejectReason());
        $this->assertSame('PHP found, Rust skipped.', $settled->finalAnswer());
        $this->assertTrue((new ToolWasApproved('lookup'))->evaluate($settled)->passed);
        $this->assertTrue((new ToolWasRejected('lookup'))->evaluate($settled)->passed);
        $this->assertSame(1, CountingTool::$executions);
    }

    protected function agent(FakeAIProvider $provider, MessageStoreInterface $store, InMemoryPersistence $persistence): Agent
    {
        return Agent::make(workflowId: self::THREAD)
            ->setAiProvider($provider)
            ->setMessageStore($store)
            ->setPersistence($persistence)
            ->addTool((new CountingTool())->withApprovalPolicy(static fn (ToolInterface $tool): string => 'Lookups cost money'));
    }

    protected function trajectory(MessageStoreInterface $store): Trajectory
    {
        return Trajectory::fromChatHistory(new ChatHistory($store, self::THREAD));
    }

    /**
     * @return list<array{?string, ?ApprovalState, ?string, bool}>
     */
    protected function describe(Trajectory $trajectory): array
    {
        return array_map(
            static fn (ToolCall $call): array => [$call->getCallId(), $call->getApprovalState(), $call->getApprovalReason(), $call->hasResult()],
            $trajectory->toolCalls('lookup'),
        );
    }
}
