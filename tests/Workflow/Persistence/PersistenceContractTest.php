<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Persistence;

use NeuronAI\Tests\Workflow\Persistence\Stub\WorkflowStoreModel;
use Illuminate\Database\Capsule\Manager as Capsule;
use NeuronAI\Workflow\Persistence\DatabasePersistence;
use NeuronAI\Workflow\Persistence\EloquentPersistence;
use NeuronAI\Workflow\Persistence\FilePersistence;
use NeuronAI\Workflow\Persistence\InMemoryPersistence;
use NeuronAI\Workflow\Persistence\PersistenceInterface;
use PDO;
use PHPUnit\Framework\TestCase;

use function array_diff;
use function fileperms;
use function file_put_contents;
use function is_dir;
use function json_encode;
use function rmdir;
use function scandir;
use function str_repeat;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

use const JSON_THROW_ON_ERROR;
use const PHP_OS_FAMILY;

/**
 * The opaque atomic store contract exercised against every built-in backend
 * that runs without external infrastructure.
 */
class PersistenceContractTest extends TestCase
{
    protected string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/neuron_store_' . uniqid();
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->directory);
    }

    /**
     * @return array<string, array{0: callable(string): PersistenceInterface}>
     */
    public static function backendProvider(): array
    {
        return [
            'in-memory' => [fn (string $dir): PersistenceInterface => new InMemoryPersistence()],
            'file' => [fn (string $dir): PersistenceInterface => new FilePersistence($dir)],
            'database' => [fn (string $dir): PersistenceInterface => self::sqliteBackend()],
            'eloquent' => [fn (string $dir): PersistenceInterface => self::eloquentBackend()],
        ];
    }

    protected static function sqliteBackend(): DatabasePersistence
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec('
            CREATE TABLE workflow_store (
                "partition" VARCHAR(255) NOT NULL,
                "key"       VARCHAR(255) NOT NULL,
                "value"     TEXT NOT NULL,
                updated_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY ("partition", "key")
            )
        ');

        return new DatabasePersistence($pdo);
    }

    protected static function eloquentBackend(): EloquentPersistence
    {
        $capsule = new Capsule();
        $capsule->addConnection([
            'driver' => 'sqlite',
            'database' => ':memory:',
        ]);
        $capsule->setAsGlobal();
        $capsule->bootEloquent();
        $capsule->getConnection()->statement('
            CREATE TABLE workflow_store (
                "partition" VARCHAR(255) NOT NULL,
                "key"       VARCHAR(255) NOT NULL,
                "value"     TEXT NOT NULL,
                updated_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY ("partition", "key")
            )
        ');

        return new EloquentPersistence(WorkflowStoreModel::class);
    }

    /** @dataProvider backendProvider */
    public function test_get_of_an_absent_record_returns_null(callable $make): void
    {
        $store = $make($this->directory);

        $this->assertNull($store->get('missing', 'record'));
    }

    /** @dataProvider backendProvider */
    public function test_initialize_creates_the_condition_key_and_related_records_atomically(callable $make): void
    {
        $store = $make($this->directory);

        $this->assertTrue($store->initializeIfAbsent('workflow', '__control', 'attempt-1', [
            '__ignition' => 'trigger',
        ]));
        $this->assertSame('attempt-1', $store->get('workflow', '__control'));
        $this->assertSame('trigger', $store->get('workflow', '__ignition'));
    }

    /** @dataProvider backendProvider */
    public function test_initialize_rejects_an_existing_condition_key_without_partial_writes(callable $make): void
    {
        $store = $make($this->directory);
        $store->initializeIfAbsent('workflow', '__control', 'attempt-1');

        $this->assertFalse($store->initializeIfAbsent('workflow', '__control', 'attempt-2', [
            'step' => 'must-not-land',
        ]));
        $this->assertSame('attempt-1', $store->get('workflow', '__control'));
        $this->assertNull($store->get('workflow', 'step'));
    }

    /** @dataProvider backendProvider */
    public function test_write_updates_all_records_when_the_condition_value_is_unchanged(callable $make): void
    {
        $store = $make($this->directory);
        $store->initializeIfAbsent('workflow', '__control', 'attempt-1', ['step' => 'first']);

        $this->assertTrue($store->writeIfUnchanged('workflow', '__control', 'attempt-1', [
            '__control' => 'attempt-2',
            'step' => 'second',
        ]));
        $this->assertSame('attempt-2', $store->get('workflow', '__control'));
        $this->assertSame('second', $store->get('workflow', 'step'));
    }

    /** @dataProvider backendProvider */
    public function test_write_rejects_a_changed_condition_value_without_partial_writes(callable $make): void
    {
        $store = $make($this->directory);
        $store->initializeIfAbsent('workflow', '__control', 'attempt-2');

        $this->assertFalse($store->writeIfUnchanged('workflow', '__control', 'attempt-1', [
            '__control' => 'attempt-3',
            'step' => 'must-not-land',
        ]));
        $this->assertSame('attempt-2', $store->get('workflow', '__control'));
        $this->assertNull($store->get('workflow', 'step'));
    }

    /** @dataProvider backendProvider */
    public function test_delete_removes_the_owned_partition_and_nothing_else(callable $make): void
    {
        $store = $make($this->directory);
        $store->initializeIfAbsent('workflow-a', '__control', 'attempt-1', ['step' => 'a']);
        $store->initializeIfAbsent('workflow-b', '__control', 'attempt-1', ['step' => 'b']);

        $this->assertTrue($store->deleteIfUnchanged('workflow-a', '__control', 'attempt-1'));
        $this->assertNull($store->get('workflow-a', '__control'));
        $this->assertNull($store->get('workflow-a', 'step'));
        $this->assertSame('b', $store->get('workflow-b', 'step'));
    }

    /** @dataProvider backendProvider */
    public function test_delete_rejects_a_changed_condition_value(callable $make): void
    {
        $store = $make($this->directory);
        $store->initializeIfAbsent('workflow', '__control', 'attempt-1', ['step' => 'result']);
        $store->writeIfUnchanged('workflow', '__control', 'attempt-1', ['__control' => 'attempt-2']);

        $this->assertFalse($store->deleteIfUnchanged('workflow', '__control', 'attempt-1'));
        $this->assertSame('attempt-2', $store->get('workflow', '__control'));
        $this->assertSame('result', $store->get('workflow', 'step'));
    }

    /** @dataProvider backendProvider */
    public function test_opaque_keys_and_values_round_trip_byte_identical(callable $make): void
    {
        $store = $make($this->directory);
        $value = "line1\nline2\t\"quoted\" — unicode ✓ \x07\xFF\xFE";

        $store->initializeIfAbsent('workflow', '__control', 'owner', [
            'user/42:thread #1' => $value,
        ]);

        $this->assertSame($value, $store->get('workflow', 'user/42:thread #1'));
    }

    /** @dataProvider backendProvider */
    public function test_hostile_partition_names_round_trip_and_delete_cleanly(callable $make): void
    {
        $store = $make($this->directory);
        $names = ['user/42:thread #1', '../../etc/passwd', 'ordine:è-123 ✓'];

        foreach ($names as $name) {
            $store->initializeIfAbsent($name, '__control', 'owner', ['step' => 'payload:' . $name]);
        }

        foreach ($names as $name) {
            $this->assertSame('payload:' . $name, $store->get($name, 'step'));
        }

        $this->assertTrue($store->deleteIfUnchanged($names[0], '__control', 'owner'));
        $this->assertNull($store->get($names[0], 'step'));
        $this->assertSame('payload:' . $names[1], $store->get($names[1], 'step'));
    }

    public function test_file_backend_keeps_hostile_names_inside_its_directory(): void
    {
        $store = new FilePersistence($this->directory);
        $store->initializeIfAbsent('../escape-attempt', '__control', 'owner', ['step' => 'payload']);

        $entries = array_diff(scandir($this->directory) ?: [], ['.', '..']);
        $this->assertCount(1, $entries);
        $this->assertSame(
            'payload',
            (new FilePersistence($this->directory))->get('../escape-attempt', 'step'),
        );
    }

    public function test_file_backend_creates_owner_only_storage(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('POSIX permission bits are not available on Windows.');
        }

        $store = new FilePersistence($this->directory);
        $store->initializeIfAbsent('workflow', '__control', 'owner');

        $directoryPermissions = fileperms($this->directory);
        $partitionPermissions = fileperms($this->directory . '/workflow.store');

        $this->assertNotFalse($directoryPermissions);
        $this->assertNotFalse($partitionPermissions);
        $this->assertSame(0, $directoryPermissions & 0o077);
        $this->assertSame(0, $partitionPermissions & 0o077);
    }

    public function test_file_backend_throws_on_a_failed_write(): void
    {
        $store = new FilePersistence($this->directory);
        $partition = str_repeat('x', 300);

        try {
            $store->initializeIfAbsent($partition, '__control', 'owner');
            $this->fail('The oversized partition filename should fail.');
        } catch (\NeuronAI\Exceptions\WorkflowException $e) {
            $this->assertStringContainsString('Unable to write partition', $e->getMessage());
        }

        $this->assertNull($store->get($partition, '__control'));
        $this->assertSame(
            [],
            array_diff(scandir($this->directory) ?: [], ['.', '..']),
        );
    }

    public function test_file_backend_rejects_a_stale_write_across_instances(): void
    {
        $first = new FilePersistence($this->directory);
        $first->initializeIfAbsent('workflow', '__control', 'attempt-1');

        $second = new FilePersistence($this->directory);
        $second->writeIfUnchanged('workflow', '__control', 'attempt-1', [
            '__control' => 'attempt-2',
        ]);

        $this->assertFalse($first->writeIfUnchanged('workflow', '__control', 'attempt-1', [
            'step' => 'must-not-land',
        ]));
        $this->assertSame('attempt-2', $first->get('workflow', '__control'));
        $this->assertNull($first->get('workflow', 'step'));
    }

    public function test_file_backend_reads_the_legacy_json_map(): void
    {
        new FilePersistence($this->directory);
        file_put_contents($this->directory . '/workflow.store', json_encode([
            '__control' => 'attempt-1',
            'step' => 'result',
        ], JSON_THROW_ON_ERROR));

        $store = new FilePersistence($this->directory);

        $this->assertSame('attempt-1', $store->get('workflow', '__control'));
        $this->assertSame('result', $store->get('workflow', 'step'));
    }

    public function test_file_backend_rejects_corrupted_partitions(): void
    {
        new FilePersistence($this->directory);
        file_put_contents($this->directory . '/corrupt.store', '{invalid');

        $this->expectException(\NeuronAI\Exceptions\PersistenceException::class);
        $this->expectExceptionMessage('Corrupted Workflow partition');

        (new FilePersistence($this->directory))->get('corrupt', 'step');
    }

    public function test_database_backend_quotes_the_configured_table_identifier(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec('
            CREATE TABLE "workflow""store" (
                "partition" VARCHAR(255) NOT NULL,
                "key"       VARCHAR(255) NOT NULL,
                "value"     TEXT NOT NULL,
                updated_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY ("partition", "key")
            )
        ');

        $store = new DatabasePersistence($pdo, 'workflow"store');

        $this->assertTrue($store->initializeIfAbsent('workflow', '__control', 'owner', [
            'step' => 'result',
        ]));
        $this->assertSame('result', $store->get('workflow', 'step'));
    }

    protected function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        foreach (scandir($directory) as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                unlink($directory . '/' . $entry);
            }
        }

        rmdir($directory);
    }
}
