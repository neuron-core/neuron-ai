<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Persistence;

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use NeuronAI\Workflow\Persistence\DatabasePersistence;
use NeuronAI\Workflow\Persistence\EloquentPersistence;
use NeuronAI\Workflow\Persistence\FilePersistence;
use NeuronAI\Workflow\Persistence\InMemoryPersistence;
use NeuronAI\Workflow\Persistence\PersistenceInterface;
use PDO;
use PHPUnit\Framework\TestCase;

use function array_diff;
use function file_put_contents;
use function is_dir;
use function rmdir;
use function scandir;
use function str_repeat;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

class WorkflowStoreModel extends Model
{
    protected $table = 'workflow_store';

    protected $guarded = [];

    public $timestamps = false;
}

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
    public function testGetOfAnAbsentRecordReturnsNull(callable $make): void
    {
        $store = $make($this->directory);

        $this->assertNull($store->get('missing', 'record'));
    }

    /** @dataProvider backendProvider */
    public function testInitializeCreatesTheConditionKeyAndRelatedRecordsAtomically(callable $make): void
    {
        $store = $make($this->directory);

        $this->assertTrue($store->initializeIfAbsent('workflow', '__control', 'attempt-1', [
            '__ignition' => 'trigger',
        ]));
        $this->assertSame('attempt-1', $store->get('workflow', '__control'));
        $this->assertSame('trigger', $store->get('workflow', '__ignition'));
    }

    /** @dataProvider backendProvider */
    public function testInitializeRejectsAnExistingConditionKeyWithoutPartialWrites(callable $make): void
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
    public function testWriteUpdatesAllRecordsWhenTheConditionValueIsUnchanged(callable $make): void
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
    public function testWriteRejectsAChangedConditionValueWithoutPartialWrites(callable $make): void
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
    public function testDeleteRemovesTheOwnedPartitionAndNothingElse(callable $make): void
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
    public function testDeleteRejectsAChangedConditionValue(callable $make): void
    {
        $store = $make($this->directory);
        $store->initializeIfAbsent('workflow', '__control', 'attempt-1', ['step' => 'result']);
        $store->writeIfUnchanged('workflow', '__control', 'attempt-1', ['__control' => 'attempt-2']);

        $this->assertFalse($store->deleteIfUnchanged('workflow', '__control', 'attempt-1'));
        $this->assertSame('attempt-2', $store->get('workflow', '__control'));
        $this->assertSame('result', $store->get('workflow', 'step'));
    }

    /** @dataProvider backendProvider */
    public function testOpaqueKeysAndValuesRoundTripByteIdentical(callable $make): void
    {
        $store = $make($this->directory);
        $value = "line1\nline2\t\"quoted\" — unicode ✓ \x07";

        $store->initializeIfAbsent('workflow', '__control', 'owner', [
            'user/42:thread #1' => $value,
        ]);

        $this->assertSame($value, $store->get('workflow', 'user/42:thread #1'));
    }

    /** @dataProvider backendProvider */
    public function testHostilePartitionNamesRoundTripAndDeleteCleanly(callable $make): void
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

    public function testFileBackendKeepsHostileNamesInsideItsDirectory(): void
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

    public function testFileBackendThrowsOnAFailedWrite(): void
    {
        $store = new FilePersistence($this->directory);

        $this->expectException(\NeuronAI\Exceptions\WorkflowException::class);
        $this->expectExceptionMessage('Unable to write partition');

        $store->initializeIfAbsent(str_repeat('x', 300), '__control', 'owner');
    }

    public function testFileBackendRejectsCorruptedPartitions(): void
    {
        new FilePersistence($this->directory);
        file_put_contents($this->directory . '/corrupt.store', '{invalid');

        $this->expectException(\NeuronAI\Exceptions\PersistenceException::class);
        $this->expectExceptionMessage('Corrupted Workflow partition');

        (new FilePersistence($this->directory))->get('corrupt', 'step');
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
