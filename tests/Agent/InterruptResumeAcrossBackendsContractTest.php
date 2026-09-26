<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Agent;

use Closure;
use NeuronAI\Agent\Agent;
use NeuronAI\Agent\Interrupt\Action;
use NeuronAI\Agent\Interrupt\ApprovalRequest;
use NeuronAI\Agent\Interrupt\ToolResultsRequest;
use NeuronAI\Chat\History\FileMessageStore;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Exceptions\InputTranslationException;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Tests\Agent\Stub\CountingTool;
use NeuronAI\Tests\Workflow\Persistence\Stub\RedisPersistenceFactory;
use NeuronAI\Tests\Workflow\Persistence\Stub\SqlPersistenceFactory;
use NeuronAI\Tools\FrontendTool;
use NeuronAI\Tools\ToolCall;
use NeuronAI\Tools\ToolInterface;
use NeuronAI\Workflow\Persistence\FilePersistence;
use NeuronAI\Workflow\Persistence\IgbinarySerializer;
use NeuronAI\Workflow\Persistence\InMemoryPersistence;
use NeuronAI\Workflow\Persistence\PersistenceInterface;
use NeuronAI\Workflow\Persistence\PhpSerializer;
use NeuronAI\Workflow\Persistence\RedisPersistence;
use NeuronAI\Workflow\Persistence\Serializer;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Redis;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

use function array_combine;
use function array_map;
use function bin2hex;
use function explode;
use function extension_loaded;
use function is_dir;
use function is_file;
use function random_bytes;
use function rmdir;
use function sys_get_temp_dir;
use function unlink;

/**
 * An approval or external-results pause is a process boundary: the run is
 * persisted by a workflow backend, the conversation by a message store, and
 * every later request builds a blank Agent. Each built-in backend must carry
 * the pending interruption, the delivered inputs and the completion across
 * that boundary identically.
 */
class InterruptResumeAcrossBackendsContractTest extends TestCase
{
    protected const THREAD = 'approval-thread';

    protected string $directory;

    protected string $table;

    protected string $redisPrefix;

    protected ?PDO $pdo = null;

    protected ?Redis $redis = null;

    protected function setUp(): void
    {
        $suffix = bin2hex(random_bytes(6));
        $this->directory = sys_get_temp_dir().'/neuron_approval_contract_'.$suffix;
        $this->table = 'approval_contract_'.$suffix;
        $this->redisPrefix = 'neuron:approval-contract:'.$suffix.':';
        CountingTool::reset();
    }

    protected function tearDown(): void
    {
        if ($this->pdo instanceof PDO) {
            $this->pdo->exec("DROP TABLE IF EXISTS {$this->table}");
            $this->pdo = null;
        }
        if ($this->redis instanceof Redis) {
            foreach ($this->redis->keys($this->redisPrefix.'*') as $key) {
                $this->redis->del($key);
            }
            $this->redis->close();
            $this->redis = null;
        }
        if (is_file($this->directory.'.sqlite')) {
            unlink($this->directory.'.sqlite');
        }
        if (is_dir($this->directory)) {
            $entries = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($this->directory, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($entries as $entry) {
                $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
            }
            rmdir($this->directory);
        }
        CountingTool::reset();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function backends(): array
    {
        $names = ['in-memory', 'file', 'redis', 'sqlite-pdo', 'sqlite-eloquent', 'pgsql-pdo', 'pgsql-eloquent'];

        return array_combine($names, array_map(static fn (string $name): array => [$name], $names));
    }

    /**
     * Prepares the backend and returns how a new process connects to it.
     *
     * @return Closure(): PersistenceInterface
     */
    protected function prepare(string $backend): Closure
    {
        if ($backend === 'in-memory') {
            $persistence = new InMemoryPersistence();

            return static fn (): PersistenceInterface => $persistence;
        }
        if ($backend === 'file') {
            return fn (): PersistenceInterface => new FilePersistence($this->directory.'/runs');
        }
        if ($backend === 'redis') {
            $this->redis = RedisPersistenceFactory::connect();

            return fn (): PersistenceInterface => new RedisPersistence(RedisPersistenceFactory::connect(), $this->redisPrefix);
        }

        [$driver, $flavour] = explode('-', $backend);
        $this->pdo = SqlPersistenceFactory::connect($driver, $this->directory.'.sqlite');
        SqlPersistenceFactory::createTable($this->pdo, $this->table, $flavour === 'eloquent');

        return fn (): PersistenceInterface => SqlPersistenceFactory::make(
            SqlPersistenceFactory::connect($driver, $this->directory.'.sqlite'),
            $this->table,
            $flavour === 'eloquent',
        );
    }

    /**
     * A blank Agent: only the thread ID, the backend and the history directory
     * survive from the previous request.
     */
    /**
     * @param ToolInterface[]|null $tools null offers the approval-gated lookup
     */
    protected function agent(
        FakeAIProvider $provider,
        PersistenceInterface $persistence,
        ?array $tools = null,
        Serializer $serializer = new PhpSerializer(),
    ): Agent {
        return Agent::make(workflowId: self::THREAD)
            ->setAiProvider($provider)
            ->setPersistence($persistence)
            ->setSerializer($serializer)
            ->setMessageStore(new FileMessageStore($this->directory.'/history'))
            ->addTool($tools ?? [(new CountingTool())->withApprovalPolicy(static fn (ToolInterface $tool): string => 'Lookups cost money')]);
    }

    #[DataProvider('backends')]
    public function test_a_paused_turn_is_inspected_decided_and_completed_by_blank_agents(string $backend): void
    {
        $connect = $this->prepare($backend);

        // Request 1: the model asks for two gated calls; nothing runs.
        $state = $this->agent(new FakeAIProvider(new ToolCallMessage(null, [
            ToolCall::make('lookup', 'call_1', ['query' => 'php']),
            ToolCall::make('lookup', 'call_2', ['query' => 'rust']),
        ])), $connect())->chat(new UserMessage('Compare php and rust'));

        $this->assertTrue($state->isInterrupted());
        $this->assertInstanceOf(ApprovalRequest::class, $state->getInterruptRequest());
        $this->assertSame(0, CountingTool::$executions);

        // Request 2: the approval UI is rebuilt from the backend alone.
        $pending = $this->agent(new FakeAIProvider(), $connect())->pendingApprovals();
        $this->assertSame(
            [
                ['call_1', 'lookup', ['query' => 'php'], 'Lookups cost money'],
                ['call_2', 'lookup', ['query' => 'rust'], 'Lookups cost money'],
            ],
            array_map(static fn (Action $action): array => [$action->id, $action->name, $action->inputs, $action->reason], $pending),
        );

        // Request 3: mixed decisions resume the run; only the approved call executes.
        $provider = new FakeAIProvider(new AssistantMessage('PHP found, Rust skipped.'));
        $final = $this->agent($provider, $connect())
            ->submitApprovalDecisions(['call_1' => 'approve', 'call_2' => ['reject', 'Not needed']])
            ->run();

        $this->assertFalse($final->isInterrupted());
        $this->assertSame('PHP found, Rust skipped.', $final->getMessage()?->getContent());
        $this->assertSame(1, CountingTool::$executions);
        $provider->assertCallCount(1);
        $results = $this->toolResults($provider->getRecorded()[0]->messages);
        $this->assertSame('Results for: php', $results['call_1']);
        $this->assertStringContainsString('Not needed', $results['call_2']);

        // The completed run releases the thread on every backend.
        $this->assertNull($connect()->get(self::THREAD, '__control'));
        $this->assertSame([], $this->agent(new FakeAIProvider(), $connect())->pendingApprovals());
        $this->assertSame(
            [UserMessage::class, ToolCallMessage::class, ToolResultMessage::class, AssistantMessage::class],
            array_map(
                static fn (Message $message): string => $message::class,
                (new FileMessageStore($this->directory.'/history'))->loadAll(self::THREAD),
            ),
        );
    }

    #[DataProvider('backends')]
    public function test_decisions_delivered_in_separate_requests_accumulate_in_the_backend(string $backend): void
    {
        $connect = $this->prepare($backend);
        $this->agent(new FakeAIProvider(new ToolCallMessage(null, [
            ToolCall::make('lookup', 'call_1', ['query' => 'php']),
            ToolCall::make('lookup', 'call_2', ['query' => 'rust']),
        ])), $connect())->chat(new UserMessage('Compare php and rust'));

        $partial = $this->agent(new FakeAIProvider(), $connect())
            ->submitApprovalDecisions(['call_1' => 'approve'])
            ->run();

        $this->assertTrue($partial->isInterrupted(), 'An incomplete decision set re-suspends.');
        $this->assertSame(0, CountingTool::$executions);
        $this->assertSame(
            ['call_2'],
            array_map(static fn (Action $action): string => $action->id, $this->agent(new FakeAIProvider(), $connect())->pendingApprovals()),
        );

        $provider = new FakeAIProvider(new AssistantMessage('Both found.'));
        $final = $this->agent($provider, $connect())
            ->submitApprovalDecisions(['call_2' => 'approve'])
            ->run();

        $this->assertSame('Both found.', $final->getMessage()?->getContent());
        $this->assertSame(2, CountingTool::$executions);
        $this->assertSame(
            ['call_1' => 'Results for: php', 'call_2' => 'Results for: rust'],
            $this->toolResults($provider->getRecorded()[0]->messages),
        );
    }

    #[DataProvider('backends')]
    public function test_decisions_replayed_after_completion_never_run_the_tool_again(string $backend): void
    {
        $connect = $this->prepare($backend);
        $this->agent(new FakeAIProvider(new ToolCallMessage(null, [
            ToolCall::make('lookup', 'call_1', ['query' => 'php']),
        ])), $connect())->chat(new UserMessage('Look up php'));
        $this->agent(new FakeAIProvider(new AssistantMessage('Done')), $connect())
            ->submitApprovalDecisions(['call_1' => 'approve'])
            ->run();

        $provider = new FakeAIProvider(new AssistantMessage('Must not be reached'));
        try {
            $this->agent($provider, $connect())->submitApprovalDecisions(['call_1' => 'approve']);
            $this->fail('A decision for a completed run must be refused before execution.');
        } catch (InputTranslationException $exception) {
            $this->assertSame('There is no persisted run to continue.', $exception->getMessage());
        }

        $this->assertSame(1, CountingTool::$executions);
        $provider->assertCallCount(0);
    }

    #[DataProvider('backends')]
    public function test_external_results_are_awaited_and_merged_with_local_results_by_blank_agents(string $backend): void
    {
        $connect = $this->prepare($backend);

        $state = $this->agent(new FakeAIProvider(new ToolCallMessage(null, [
            new ToolCall('browser', 'external', ['selector' => '#title'], deferred: true),
            new ToolCall('lookup', 'local', ['query' => 'php']),
        ])), $connect(), [new FrontendTool('browser'), new CountingTool()])->chat(new UserMessage('Read the title and look up php'));

        $request = $state->getInterruptRequest();
        $this->assertInstanceOf(ToolResultsRequest::class, $request);
        $this->assertSame(['external'], array_map(static fn (ToolCall $call): ?string => $call->getCallId(), $request->getToolCalls()));
        $this->assertSame(1, CountingTool::$executions, 'The local call runs before the pause.');

        // The browser answers later; the resuming Agent offers no tools at all.
        $provider = new FakeAIProvider(new AssistantMessage('The title is Example.'));
        $final = $this->agent($provider, $connect(), [])
            ->submitToolResults(['external' => ['result' => ['title' => 'Example']]])
            ->run();

        $this->assertSame('The title is Example.', $final->getMessage()?->getContent());
        $this->assertSame(1, CountingTool::$executions, 'The completed local call is not repeated.');
        $this->assertSame(
            ['external' => '{"title":"Example"}', 'local' => 'Results for: php'],
            $this->toolResults($provider->getRecorded()[0]->messages),
        );
        $this->assertNull($connect()->get(self::THREAD, '__control'));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function serializers(): array
    {
        return ['php' => ['php'], 'igbinary' => ['igbinary']];
    }

    #[DataProvider('serializers')]
    public function test_every_serializer_carries_the_pause_and_the_decisions(string $serializer): void
    {
        if ($serializer === 'igbinary' && !extension_loaded('igbinary')) {
            $this->markTestSkipped('The igbinary PHP extension is unavailable.');
        }
        $makeSerializer = static fn (): Serializer => $serializer === 'php' ? new PhpSerializer() : new IgbinarySerializer();
        $connect = $this->prepare('file');

        $this->agent(new FakeAIProvider(new ToolCallMessage(null, [
            ToolCall::make('lookup', 'call_1', ['query' => 'php']),
        ])), $connect(), serializer: $makeSerializer())->chat(new UserMessage('Look up php'));

        $this->assertSame(
            ['call_1'],
            array_map(static fn (Action $action): string => $action->id, $this->agent(new FakeAIProvider(), $connect(), serializer: $makeSerializer())->pendingApprovals()),
        );
        $final = $this->agent(new FakeAIProvider(new AssistantMessage('Done')), $connect(), serializer: $makeSerializer())
            ->submitApprovalDecisions(['call_1' => 'approve'])
            ->run();

        $this->assertSame('Done', $final->getMessage()?->getContent());
        $this->assertSame(1, CountingTool::$executions);
    }

    /**
     * @param Message[] $messages
     * @return array<string, string>
     */
    protected function toolResults(array $messages): array
    {
        $results = [];
        foreach ($messages as $message) {
            if ($message instanceof ToolResultMessage) {
                foreach ($message->getToolCalls() as $call) {
                    $results[(string) $call->getCallId()] = (string) $call->getResult();
                }
            }
        }

        return $results;
    }
}
