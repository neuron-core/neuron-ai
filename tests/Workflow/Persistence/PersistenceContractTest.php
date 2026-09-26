<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Persistence;

use NeuronAI\Tests\Workflow\Persistence\Stub\RedisPersistenceFactory;
use NeuronAI\Tests\Workflow\Persistence\Stub\SqlPersistenceFactory;
use NeuronAI\Workflow\Persistence\FilePersistence;
use NeuronAI\Workflow\Persistence\InMemoryPersistence;
use NeuronAI\Workflow\Persistence\PersistenceInterface;
use NeuronAI\Workflow\Persistence\RedisPersistence;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Redis;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

use function array_keys;
use function array_map;
use function bin2hex;
use function explode;
use function file_exists;
use function is_dir;
use function random_bytes;
use function rmdir;
use function str_repeat;
use function sys_get_temp_dir;
use function unlink;
use function array_combine;
use function str_ends_with;

/**
 * The opaque atomic store contract, exercised against every built-in backend.
 * Backends that need infrastructure (PostgreSQL, MySQL, Redis) skip when it is
 * not configured.
 */
class PersistenceContractTest extends TestCase
{
    protected string $directory;

    protected ?PDO $pdo = null;

    protected string $table;

    protected ?Redis $redis = null;

    protected string $redisPrefix;

    protected function setUp(): void
    {
        $suffix = bin2hex(random_bytes(6));
        $this->directory = sys_get_temp_dir() . '/neuron_store_' . $suffix;
        $this->table = 'workflow_contract_' . $suffix;
        $this->redisPrefix = 'neuron:contract:' . $suffix . ':';
    }

    protected function tearDown(): void
    {
        if ($this->pdo instanceof PDO) {
            $this->pdo->exec("DROP TABLE IF EXISTS {$this->table}");
            $this->pdo = null;
        }
        if ($this->redis instanceof Redis) {
            foreach ($this->redis->keys($this->redisPrefix . '*') as $key) {
                $this->redis->del($key);
            }
            $this->redis->close();
            $this->redis = null;
        }
        if (file_exists($this->directory . '.sqlite')) {
            unlink($this->directory . '.sqlite');
        }
        $this->removeDirectory($this->directory);
    }

    /** @return array<string, array{string}> */
    public static function backendProvider(): array
    {
        $names = ['in-memory', 'file', 'redis'];
        foreach (['sqlite', 'pgsql', 'mysql'] as $driver) {
            $names[] = $driver . '-pdo';
            $names[] = $driver . '-eloquent';
        }

        return array_combine($names, array_map(fn (string $name): array => [$name], $names));
    }

    protected function backend(string $name): PersistenceInterface
    {
        if ($name === 'in-memory') {
            return new InMemoryPersistence();
        }
        if ($name === 'file') {
            return new FilePersistence($this->directory);
        }
        if ($name === 'redis') {
            $this->redis = RedisPersistenceFactory::connect();

            return new RedisPersistence($this->redis, $this->redisPrefix);
        }

        [$driver, $flavour] = explode('-', $name);
        $this->pdo = SqlPersistenceFactory::connect($driver, $this->directory . '.sqlite');
        SqlPersistenceFactory::createTable($this->pdo, $this->table, $flavour === 'eloquent');

        return SqlPersistenceFactory::make($this->pdo, $this->table, $flavour === 'eloquent');
    }

    #[DataProvider('backendProvider')]
    public function test_get_of_an_absent_record_returns_null(string $backend): void
    {
        $store = $this->backend($backend);

        $this->assertNull($store->get('missing', 'record'));
    }

    #[DataProvider('backendProvider')]
    public function test_initialize_creates_the_condition_key_and_related_records_atomically(string $backend): void
    {
        $store = $this->backend($backend);

        $this->assertTrue($store->initializeIfAbsent('workflow', '__control', 'attempt-1', [
            '__ignition' => 'trigger',
        ]));
        $this->assertSame('attempt-1', $store->get('workflow', '__control'));
        $this->assertSame('trigger', $store->get('workflow', '__ignition'));
    }

    #[DataProvider('backendProvider')]
    public function test_initialize_rejects_an_existing_condition_key_without_partial_writes(string $backend): void
    {
        $store = $this->backend($backend);
        $store->initializeIfAbsent('workflow', '__control', 'attempt-1');

        $this->assertFalse($store->initializeIfAbsent('workflow', '__control', 'attempt-2', [
            'step' => 'must-not-land',
        ]));
        $this->assertSame('attempt-1', $store->get('workflow', '__control'));
        $this->assertNull($store->get('workflow', 'step'));
    }

    #[DataProvider('backendProvider')]
    public function test_initialize_keeps_records_already_stored_under_other_keys(string $backend): void
    {
        $store = $this->backend($backend);
        $store->initializeIfAbsent('workflow', 'earlier', 'keep');

        $this->assertTrue($store->initializeIfAbsent('workflow', '__control', 'owner', ['step' => 'result']));
        $this->assertSame('keep', $store->get('workflow', 'earlier'));
        $this->assertSame('owner', $store->get('workflow', '__control'));
        $this->assertSame('result', $store->get('workflow', 'step'));
    }

    #[DataProvider('backendProvider')]
    public function test_the_initial_value_wins_over_a_related_record_with_the_condition_key(string $backend): void
    {
        if (str_ends_with($backend, '-eloquent')) {
            $this->markTestSkipped('Known framework issue: EloquentPersistence lets the related record override the initial value.');
        }
        $store = $this->backend($backend);

        $this->assertTrue($store->initializeIfAbsent('workflow', '__control', 'owner', [
            '__control' => 'related',
            'step' => 'result',
        ]));
        $this->assertSame('owner', $store->get('workflow', '__control'));
        $this->assertSame('result', $store->get('workflow', 'step'));
    }

    #[DataProvider('backendProvider')]
    public function test_write_updates_all_records_when_the_condition_value_is_unchanged(string $backend): void
    {
        $store = $this->backend($backend);
        $store->initializeIfAbsent('workflow', '__control', 'attempt-1', ['step' => 'first']);

        $this->assertTrue($store->writeIfUnchanged('workflow', '__control', 'attempt-1', [
            '__control' => 'attempt-2',
            'step' => 'second',
            'memo' => 'added',
        ]));
        $this->assertSame('attempt-2', $store->get('workflow', '__control'));
        $this->assertSame('second', $store->get('workflow', 'step'));
        $this->assertSame('added', $store->get('workflow', 'memo'));
    }

    #[DataProvider('backendProvider')]
    public function test_write_rejects_a_changed_condition_value_without_partial_writes(string $backend): void
    {
        $store = $this->backend($backend);
        $store->initializeIfAbsent('workflow', '__control', 'attempt-2', ['step' => 'first']);

        $this->assertFalse($store->writeIfUnchanged('workflow', '__control', 'attempt-1', [
            '__control' => 'attempt-3',
            'step' => 'must-not-land',
            'memo' => 'must-not-land',
        ]));
        $this->assertSame('attempt-2', $store->get('workflow', '__control'));
        $this->assertSame('first', $store->get('workflow', 'step'));
        $this->assertNull($store->get('workflow', 'memo'));
    }

    #[DataProvider('backendProvider')]
    public function test_write_and_delete_without_the_condition_key_change_nothing(string $backend): void
    {
        $store = $this->backend($backend);
        $store->initializeIfAbsent('existing', 'other', 'owner', ['step' => 'kept']);

        foreach (['missing', 'existing'] as $partition) {
            $this->assertFalse($store->writeIfUnchanged($partition, '__control', 'owner', ['step' => 'rejected']));
            $this->assertFalse($store->writeIfUnchanged($partition, '__control', '', []));
            $this->assertFalse($store->deleteIfUnchanged($partition, '__control', 'owner'));
            $this->assertFalse($store->deleteIfUnchanged($partition, '__control', ''));
        }

        $this->assertNull($store->get('missing', 'step'));
        $this->assertNull($store->get('missing', '__control'));
        $this->assertSame('kept', $store->get('existing', 'step'));
        $this->assertSame('owner', $store->get('existing', 'other'));
    }

    #[DataProvider('backendProvider')]
    public function test_the_condition_is_compared_byte_for_byte(string $backend): void
    {
        $store = $this->backend($backend);
        $store->initializeIfAbsent('workflow', '__control', 'owner-1', ['step' => 'first']);

        foreach (['Owner-1', 'OWNER-1', 'owner-1 ', ' owner-1', "owner-1\0", "owner-1\n", 'owner-', 'owner-10', ''] as $nearMiss) {
            $label = bin2hex($nearMiss);
            $this->assertFalse($store->writeIfUnchanged('workflow', '__control', $nearMiss, ['step' => 'rejected']), $label);
            $this->assertFalse($store->deleteIfUnchanged('workflow', '__control', $nearMiss), $label);
        }

        $this->assertSame('owner-1', $store->get('workflow', '__control'));
        $this->assertSame('first', $store->get('workflow', 'step'));
    }

    #[DataProvider('backendProvider')]
    public function test_a_condition_only_write_succeeds_without_changing_records(string $backend): void
    {
        $store = $this->backend($backend);
        $store->initializeIfAbsent('workflow', '__control', 'owner', ['step' => 'first']);

        $this->assertTrue($store->writeIfUnchanged('workflow', '__control', 'owner', []));
        $this->assertSame('owner', $store->get('workflow', '__control'));
        $this->assertSame('first', $store->get('workflow', 'step'));
    }

    #[DataProvider('backendProvider')]
    public function test_an_empty_value_is_a_stored_value_not_an_absent_record(string $backend): void
    {
        $store = $this->backend($backend);

        $this->assertTrue($store->initializeIfAbsent('workflow', '__control', '', ['step' => '']));
        $this->assertSame('', $store->get('workflow', '__control'));
        $this->assertSame('', $store->get('workflow', 'step'));
        $this->assertFalse($store->initializeIfAbsent('workflow', '__control', 'other'));
        $this->assertTrue($store->writeIfUnchanged('workflow', '__control', '', ['step' => 'second']));
        $this->assertSame('second', $store->get('workflow', 'step'));
        $this->assertTrue($store->deleteIfUnchanged('workflow', '__control', ''));
        $this->assertNull($store->get('workflow', '__control'));
    }

    #[DataProvider('backendProvider')]
    public function test_delete_removes_the_whole_partition_and_nothing_else(string $backend): void
    {
        $store = $this->backend($backend);
        $partitions = ['workflow', 'workflow:b', 'workflo', 'Workflow'];
        foreach ($partitions as $partition) {
            $store->initializeIfAbsent($partition, '__control', 'attempt-1', ['step' => $partition]);
        }
        $store->writeIfUnchanged('workflow', '__control', 'attempt-1', ['memo' => 'written later']);

        $this->assertTrue($store->deleteIfUnchanged('workflow', '__control', 'attempt-1'));

        $this->assertNull($store->get('workflow', '__control'));
        $this->assertNull($store->get('workflow', 'step'));
        $this->assertNull($store->get('workflow', 'memo'));
        foreach (['workflow:b', 'workflo', 'Workflow'] as $partition) {
            $this->assertSame($partition, $store->get($partition, 'step'));
            $this->assertSame('attempt-1', $store->get($partition, '__control'));
        }
    }

    #[DataProvider('backendProvider')]
    public function test_delete_rejects_a_changed_condition_value(string $backend): void
    {
        $store = $this->backend($backend);
        $store->initializeIfAbsent('workflow', '__control', 'attempt-1', ['step' => 'result']);
        $store->writeIfUnchanged('workflow', '__control', 'attempt-1', ['__control' => 'attempt-2']);

        $this->assertFalse($store->deleteIfUnchanged('workflow', '__control', 'attempt-1'));
        $this->assertSame('attempt-2', $store->get('workflow', '__control'));
        $this->assertSame('result', $store->get('workflow', 'step'));
    }

    #[DataProvider('backendProvider')]
    public function test_a_deleted_partition_starts_a_new_generation_without_leftovers(string $backend): void
    {
        $store = $this->backend($backend);
        $store->initializeIfAbsent('workflow', '__control', 'generation-1', ['step' => 'old', 'memo' => 'old']);
        $store->deleteIfUnchanged('workflow', '__control', 'generation-1');

        $this->assertTrue($store->initializeIfAbsent('workflow', '__control', 'generation-2', ['step' => 'new']));
        $this->assertSame('generation-2', $store->get('workflow', '__control'));
        $this->assertSame('new', $store->get('workflow', 'step'));
        $this->assertNull($store->get('workflow', 'memo'));
        $this->assertFalse($store->writeIfUnchanged('workflow', '__control', 'generation-1', ['step' => 'stale']));
        $this->assertSame('new', $store->get('workflow', 'step'));
    }

    #[DataProvider('backendProvider')]
    public function test_opaque_keys_and_values_round_trip_byte_identical(string $backend): void
    {
        $store = $this->backend($backend);
        $records = [
            'user/42:thread #1' => "line1\nline2\t\"quoted\" — unicode ✓ \x07\xFF\xFE",
            "key\0with\xFFbytes" => "value\0with\xFEbytes",
            'run/step::memo' => 's:3:"foo";',
            '' => 'empty key',
            'large' => str_repeat("\0\xFF✓", 100_000),
        ];

        $store->initializeIfAbsent('workflow', '__control', "control\0\xFF", $records);

        $this->assertSame("control\0\xFF", $store->get('workflow', '__control'));
        foreach ($records as $key => $value) {
            $this->assertSame($value, $store->get('workflow', (string) $key), bin2hex((string) $key));
        }
        $this->assertTrue($store->writeIfUnchanged('workflow', '__control', "control\0\xFF", []));
    }

    #[DataProvider('backendProvider')]
    public function test_hostile_partition_names_stay_distinct_and_delete_cleanly(string $backend): void
    {
        $store = $this->backend($backend);
        $names = [
            'user/42:thread #1', '../../etc/passwd', '..', '.', '/', '%2F', '%252F', 'a\\b',
            "nul\0byte", "line\nbreak", 'ordine:è-123 ✓', 'Order:A', 'order:a', "robert'); DROP TABLE workflow_store;--",
        ];

        foreach ($names as $name) {
            $this->assertTrue($store->initializeIfAbsent($name, '__control', 'owner', ['step' => 'payload:' . $name]));
        }
        foreach ($names as $name) {
            $this->assertSame('payload:' . $name, $store->get($name, 'step'), bin2hex($name));
        }

        foreach ($names as $index => $name) {
            $this->assertTrue($store->deleteIfUnchanged($name, '__control', 'owner'), bin2hex($name));
            $this->assertNull($store->get($name, 'step'));
            foreach (array_keys($names) as $other) {
                if ($other > $index) {
                    $this->assertSame('payload:' . $names[$other], $store->get($names[$other], 'step'));
                }
            }
        }
    }

    protected function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $entries = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($entries as $entry) {
            $entry->isDir() && !$entry->isLink() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }

        rmdir($directory);
    }
}
