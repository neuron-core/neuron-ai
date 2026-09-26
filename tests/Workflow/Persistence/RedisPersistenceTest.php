<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Persistence;

use NeuronAI\Exceptions\PersistenceException;
use NeuronAI\Tests\Workflow\Persistence\Stub\RedisPersistenceFactory;
use NeuronAI\Tests\Workflow\Persistence\Stub\ScriptFailingRedis;
use NeuronAI\Workflow\Persistence\RedisPersistence;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Redis;
use RedisException;

use function array_sum;
use function bin2hex;
use function fclose;
use function fgets;
use function fwrite;
use function json_decode;
use function proc_close;
use function proc_open;
use function proc_terminate;
use function random_bytes;
use function stream_get_contents;
use function stream_set_timeout;

use const JSON_THROW_ON_ERROR;
use const PHP_BINARY;

class RedisPersistenceTest extends TestCase
{
    protected ?Redis $client = null;
    protected string $prefix;
    protected RedisPersistence $store;

    protected function setUp(): void
    {
        $this->client = RedisPersistenceFactory::connect();
        $this->prefix = 'neuron:test:' . bin2hex(random_bytes(8)) . ':';
        $this->store = new RedisPersistence($this->client, $this->prefix);
    }

    protected function tearDown(): void
    {
        if (!$this->client instanceof Redis) {
            return;
        }

        foreach ($this->client->keys($this->prefix . '*') as $key) {
            $this->client->del($key);
        }
        $this->client->close();
    }

    public function test_initialization_preserves_other_records_and_the_initial_value_wins(): void
    {
        self::assertNull($this->store->get('workflow', '__control'));
        self::assertTrue($this->store->initializeIfAbsent('workflow', 'existing', 'keep'));
        self::assertTrue($this->store->initializeIfAbsent('workflow', '__control', 'owner', [
            '__control' => 'overridden',
            'step' => 'result',
        ]));
        self::assertFalse($this->store->initializeIfAbsent('workflow', '__control', 'other', ['rejected' => 'result']));
        self::assertSame('owner', $this->store->get('workflow', '__control'));
        self::assertSame('keep', $this->store->get('workflow', 'existing'));
        self::assertSame('result', $this->store->get('workflow', 'step'));
        self::assertNull($this->store->get('workflow', 'rejected'));
    }

    public function test_stale_writes_and_deletes_cannot_affect_a_new_owner_across_connections(): void
    {
        $this->store->initializeIfAbsent('workflow', '__control', 'owner', ['step' => 'first']);
        $otherClient = RedisPersistenceFactory::connect();
        try {
            $other = new RedisPersistence($otherClient, $this->prefix);
            self::assertTrue($other->writeIfUnchanged('workflow', '__control', 'owner', [
                '__control' => 'new-owner',
                'step' => 'second',
            ]));
        } finally {
            $otherClient->close();
        }

        self::assertFalse($this->store->writeIfUnchanged('workflow', '__control', 'owner', [
            '__control' => 'stale-owner',
            'rejected' => 'result',
        ]));
        self::assertFalse($this->store->deleteIfUnchanged('workflow', '__control', 'owner'));
        self::assertSame('new-owner', $this->store->get('workflow', '__control'));
        self::assertSame('second', $this->store->get('workflow', 'step'));
        self::assertNull($this->store->get('workflow', 'rejected'));
    }

    public function test_missing_conditions_and_empty_writes_are_distinct_from_empty_values(): void
    {
        self::assertFalse($this->store->writeIfUnchanged('workflow', 'control', '', ['step' => 'rejected']));
        self::assertFalse($this->store->writeIfUnchanged('workflow', 'control', '', []));
        self::assertFalse($this->store->deleteIfUnchanged('workflow', 'control', ''));
        self::assertNull($this->store->get('workflow', 'step'));

        self::assertTrue($this->store->initializeIfAbsent('workflow', 'control', ''));
        self::assertFalse($this->store->initializeIfAbsent('workflow', 'control', 'other'));
        self::assertSame('', $this->store->get('workflow', 'control'));
        self::assertTrue($this->store->writeIfUnchanged('workflow', 'control', '', []));
        self::assertTrue($this->store->deleteIfUnchanged('workflow', 'control', ''));
        self::assertNull($this->store->get('workflow', 'control'));
    }

    public function test_arbitrary_partition_names_and_keys_remain_isolated(): void
    {
        $partitions = ['user/42:thread #1', '../../etc/passwd', 'ordine:è-123 ✓', "binary\0\xFF", '{same}:a', '{same}:b'];
        foreach ($partitions as $partition) {
            $this->store->initializeIfAbsent($partition, '', 'owner', [
                "key\0\xFF" => "value\0\xFF",
            ]);
            self::assertTrue($this->store->initializeIfAbsent($partition, '0', $partition));
        }

        foreach ($partitions as $partition) {
            self::assertSame($partition, $this->store->get($partition, '0'));
            self::assertSame("value\0\xFF", $this->store->get($partition, "key\0\xFF"));
            self::assertTrue($this->store->deleteIfUnchanged($partition, '', 'owner'));
            self::assertNull($this->store->get($partition, '0'));
            self::assertNull($this->store->get($partition, "key\0\xFF"));
            self::assertSame(0, $this->client->exists($this->prefix . $partition));
        }
    }

    public function test_prefixes_isolate_stores_and_preserve_the_client_prefix(): void
    {
        $other = new RedisPersistence($this->client, $this->prefix . 'other:');
        $this->store->initializeIfAbsent('workflow', 'control', 'owner', ['step' => 'first']);
        $other->initializeIfAbsent('workflow', 'control', 'owner', ['step' => 'second']);
        self::assertTrue($this->store->deleteIfUnchanged('workflow', 'control', 'owner'));
        self::assertSame('second', $other->get('workflow', 'step'));

        $this->client->setOption(Redis::OPT_PREFIX, $this->prefix . 'client:');
        try {
            $store = new RedisPersistence($this->client);
            self::assertTrue($store->initializeIfAbsent('workflow', 'control', 'owner'));
            self::assertSame('owner', $store->get('workflow', 'control'));
            self::assertSame($this->prefix . 'client:', $this->client->getOption(Redis::OPT_PREFIX));
        } finally {
            $this->client->setOption(Redis::OPT_PREFIX, '');
        }
        self::assertSame(1, $this->client->exists($this->prefix . 'client:neuron:workflow:workflow'));
    }

    public function test_values_remain_byte_exact_with_client_serialization_enabled(): void
    {
        $this->client->setOption(Redis::OPT_SERIALIZER, Redis::SERIALIZER_PHP);
        $values = ['', '0', '01', '1', 'b:0;', 's:3:"foo";', "line\n\0\xFF\xFE ✓"];
        foreach ($values as $index => $value) {
            $partition = (string) $index;
            self::assertTrue($this->store->initializeIfAbsent($partition, 'control', $value, ['step' => $value]));
            self::assertSame($value, $this->store->get($partition, 'control'));
            self::assertSame($value, $this->store->get($partition, 'step'));
            self::assertFalse($this->store->writeIfUnchanged($partition, 'control', $value . "\0", ['step' => 'rejected']));
            self::assertFalse($this->store->deleteIfUnchanged($partition, 'control', $value . "\0"));
            self::assertTrue($this->store->writeIfUnchanged($partition, 'control', $value, ['step' => 'updated']));
            self::assertTrue($this->store->deleteIfUnchanged($partition, 'control', $value));
        }
        self::assertSame(Redis::SERIALIZER_PHP, $this->client->getOption(Redis::OPT_SERIALIZER));
    }

    /** @return array<string, array{string}> */
    public static function operationProvider(): array
    {
        return ['get' => ['get'], 'initialize' => ['initialize'], 'write' => ['write'], 'delete' => ['delete']];
    }

    #[DataProvider('operationProvider')]
    public function test_redis_errors_are_not_reported_as_missing_records_or_conflicts(string $operation): void
    {
        $this->client->set($this->prefix . 'workflow', 'wrong-type');
        $this->expectException(PersistenceException::class);
        $this->expectExceptionMessage('WRONGTYPE');

        match ($operation) {
            'get' => $this->store->get('workflow', 'control'),
            'initialize' => $this->store->initializeIfAbsent('workflow', 'control', 'owner'),
            'write' => $this->store->writeIfUnchanged('workflow', 'control', 'owner', ['step' => 'result']),
            default => $this->store->deleteIfUnchanged('workflow', 'control', 'owner'),
        };
    }

    /** @return array<string, array{string}> */
    public static function modeProvider(): array
    {
        return ['transaction' => ['transaction'], 'pipeline' => ['pipeline']];
    }

    #[DataProvider('operationProvider')]
    public function test_a_failed_script_is_not_reported_as_a_missing_record_or_conflict(string $operation): void
    {
        $client = new ScriptFailingRedis();
        $store = new RedisPersistence($client, 'tenant:');

        try {
            match ($operation) {
                'get' => $store->get('workflow', 'control'),
                'initialize' => $store->initializeIfAbsent('workflow', 'control', 'owner'),
                'write' => $store->writeIfUnchanged('workflow', 'control', 'owner', ['step' => 'result']),
                default => $store->deleteIfUnchanged('workflow', 'control', 'owner'),
            };
            self::fail('A failed script must raise.');
        } catch (PersistenceException $e) {
            self::assertSame('Workflow Redis persistence failed: ERR script failed', $e->getMessage());
        }
        self::assertSame(1, $client->evaluated[0]['keys']);
        self::assertSame('tenant:workflow', $client->evaluated[0]['args'][0]);
    }

    public function test_a_client_exception_is_wrapped_with_its_cause(): void
    {
        $client = new ScriptFailingRedis();
        $client->exception = new RedisException('Connection lost', 7);

        try {
            (new RedisPersistence($client))->writeIfUnchanged('workflow', 'control', 'owner', ['step' => 'result']);
            self::fail('A client exception must raise.');
        } catch (PersistenceException $e) {
            self::assertSame('Workflow Redis persistence failed: Connection lost', $e->getMessage());
            self::assertSame(7, $e->getCode());
            self::assertSame($client->exception, $e->getPrevious());
        }
    }

    public function test_a_partition_is_one_plain_hash_without_expiry(): void
    {
        $this->store->initializeIfAbsent('order:1', '__control', 'owner', ['run/step' => 'result']);
        $this->store->writeIfUnchanged('order:1', '__control', 'owner', ['__control' => 'next']);

        self::assertSame([$this->prefix . 'order:1'], $this->client->keys($this->prefix . '*'));
        self::assertSame(Redis::REDIS_HASH, $this->client->type($this->prefix . 'order:1'));
        self::assertEqualsCanonicalizing(
            ['__control' => 'next', 'run/step' => 'result'],
            $this->client->hGetAll($this->prefix . 'order:1'),
        );
        self::assertSame(-1, $this->client->ttl($this->prefix . 'order:1'));
    }

    public function test_a_write_beyond_the_script_argument_limit_is_all_or_nothing(): void
    {
        $this->store->initializeIfAbsent('workflow', 'control', 'owner');
        $records = [];
        for ($index = 0; $index < 10_000; $index++) {
            $records['step-' . $index] = 'result';
        }

        // Whether Lua's unpack limit rejects the write depends on the Redis build.
        try {
            self::assertTrue($this->store->writeIfUnchanged('workflow', 'control', 'owner', $records));
            self::assertSame(10_001, $this->client->hLen($this->prefix . 'workflow'));
        } catch (PersistenceException $e) {
            self::assertStringStartsWith('Workflow Redis persistence failed: ', $e->getMessage());
            self::assertSame(['control' => 'owner'], $this->client->hGetAll($this->prefix . 'workflow'));
        }
    }

    #[DataProvider('modeProvider')]
    public function test_queued_clients_are_rejected_before_enqueuing_a_mutation(string $mode): void
    {
        $this->client->multi($mode === 'transaction' ? Redis::MULTI : Redis::PIPELINE);
        try {
            $this->store->initializeIfAbsent('workflow', 'control', 'owner');
            self::fail('A queued client must be rejected.');
        } catch (PersistenceException $e) {
            self::assertStringContainsString('transaction or pipeline', $e->getMessage());
            self::assertSame([], $this->client->exec());
        } finally {
            if ($this->client->getMode() !== Redis::ATOMIC) {
                $this->client->discard();
            }
        }
        self::assertNull($this->store->get('workflow', 'control'));
    }

    /** @return array<string, array{string}> */
    public static function raceProvider(): array
    {
        return ['initialize' => ['initialize'], 'write' => ['write'], 'delete' => ['delete']];
    }

    #[DataProvider('raceProvider')]
    public function test_competing_workers_commit_only_one_transition(string $action): void
    {
        if ($action !== 'initialize') {
            $this->store->initializeIfAbsent('race', '__control', 'owner');
        }

        $processes = [];
        $pipes = [];
        try {
            foreach (['first', 'second'] as $index => $marker) {
                $command = [PHP_BINARY, '-r',
                    'require $argv[1]; array_splice($argv, 1, 1); NeuronAI\\Tests\\Workflow\\Persistence\\Stub\\RedisPersistenceWorker::run($argv);',
                    __DIR__ . '/../../../vendor/autoload.php',
                    $this->prefix, $action === 'delete' && $index === 1 ? 'write' : $action, $marker,
                ];
                $process = proc_open($command, [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $workerPipes);
                self::assertIsResource($process);
                $processes[] = $process;
                $pipes[] = $workerPipes;
                stream_set_timeout($workerPipes[1], 20);
                self::assertSame("ready\n", fgets($workerPipes[1]));
            }
            foreach ($pipes as $workerPipes) {
                fwrite($workerPipes[0], "go\n");
                fclose($workerPipes[0]);
            }
            $results = [];
            foreach ($processes as $index => $process) {
                $output = stream_get_contents($pipes[$index][1]);
                $error = stream_get_contents($pipes[$index][2]);
                fclose($pipes[$index][1]);
                fclose($pipes[$index][2]);
                $exitCode = proc_close($process);
                unset($processes[$index]);
                self::assertSame(0, $exitCode, $error);
                $results[] = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
            }
            self::assertSame(1, array_sum($results));
            if ($action === 'delete' && $results[0]) {
                self::assertNull($this->store->get('race', '__control'));
                self::assertNull($this->store->get('race', 'second'));
            } else {
                $winner = $results[0] ? 'first' : 'second';
                $loser = $results[0] ? 'second' : 'first';
                self::assertSame($winner, $this->store->get('race', '__control'));
                self::assertSame('result', $this->store->get('race', $winner));
                self::assertNull($this->store->get('race', $loser));
            }
        } finally {
            foreach ($processes as $process) {
                proc_terminate($process);
                proc_close($process);
            }
        }
    }
    public function test_acknowledged_completion_deletes_every_record_of_the_run(): void
    {
        $make = fn (): \NeuronAI\Workflow\Workflow => \NeuronAI\Tests\Workflow\Stub\KeyedWorkflow::make('retained')
            ->setPersistence($this->store)->retainCompletionUntilAcknowledged();
        $started = $make()->run(\NeuronAI\Workflow\Executor\ExecutionRequest::start());
        $make()->run(\NeuronAI\Workflow\Executor\ExecutionRequest::resume([], $started->getRunId(), $started->getExecutionAttempt()));
        $make()->acknowledge($started->getRunId());
        self::assertSame([], $this->client->keys($this->prefix . '*'));
    }

}
