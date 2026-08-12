<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Persistence;

use NeuronAI\Workflow\Persistence\DatabasePersistence;
use NeuronAI\Workflow\Persistence\FilePersistence;
use NeuronAI\Workflow\Persistence\InMemoryPersistence;
use NeuronAI\Workflow\Persistence\PersistenceInterface;
use PDO;
use PHPUnit\Framework\TestCase;

use function is_dir;
use function rmdir;
use function scandir;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;
use function array_diff;
use function str_repeat;

/**
 * The three-method store contract — put/get/delete over (partition, key) —
 * exercised against every built-in backend that runs without external
 * infrastructure. Values are opaque strings; the backend interprets nothing.
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

    /**
     * @dataProvider backendProvider
     */
    public function testPutThenGetReturnsTheValue(callable $make): void
    {
        $store = $make($this->directory);

        $store->put('run_a', 'step-1', 'payload');

        $this->assertSame('payload', $store->get('run_a', 'step-1'));
    }

    /**
     * @dataProvider backendProvider
     */
    public function testGetOfAnAbsentKeyReturnsNull(callable $make): void
    {
        $store = $make($this->directory);

        $store->put('run_a', 'step-1', 'payload');

        $this->assertNull($store->get('run_a', 'other-key'));
        $this->assertNull($store->get('other_partition', 'step-1'));
    }

    /**
     * @dataProvider backendProvider
     */
    public function testPutOverwrites(callable $make): void
    {
        $store = $make($this->directory);

        $store->put('run_a', 'step-1', 'first');
        $store->put('run_a', 'step-1', 'second');

        $this->assertSame('second', $store->get('run_a', 'step-1'));
    }

    /**
     * @dataProvider backendProvider
     */
    public function testDeleteRemovesTheWholePartitionAndNothingElse(callable $make): void
    {
        $store = $make($this->directory);

        $store->put('run_a', 'step-1', 'a1');
        $store->put('run_a', 'step-2', 'a2');
        $store->put('run_b', 'step-1', 'b1');

        $store->delete('run_a');

        $this->assertNull($store->get('run_a', 'step-1'));
        $this->assertNull($store->get('run_a', 'step-2'));
        $this->assertSame('b1', $store->get('run_b', 'step-1'));
    }

    /**
     * @dataProvider backendProvider
     */
    public function testDeleteOfAnUnknownPartitionDoesNotThrow(callable $make): void
    {
        $store = $make($this->directory);

        $store->delete('never_seen');

        $this->assertNull($store->get('never_seen', 'anything'));
    }

    /**
     * @dataProvider backendProvider
     */
    public function testKeysWithHostileCharactersRoundTrip(callable $make): void
    {
        // Keys are app-influenced strings; they are stored as keys inside
        // the partition, so no backend may choke on them.
        $store = $make($this->directory);

        $store->put('some_partition', 'user/42:thread #1', 'run_a');

        $this->assertSame('run_a', $store->get('some_partition', 'user/42:thread #1'));
    }

    /**
     * @dataProvider backendProvider
     */
    public function testValuesRoundTripByteIdentical(callable $make): void
    {
        $store = $make($this->directory);
        $value = "line1\nline2\t\"quoted\" — unicode ✓ \x07";

        $store->put('run_a', 'blob', $value);

        $this->assertSame($value, $store->get('run_a', 'blob'));
    }

    /**
     * Partition names are business-owned addresses (a threadId, 'order:123');
     * the contract makes their safe storage the backend's obligation.
     *
     * @dataProvider backendProvider
     */
    public function testHostilePartitionNamesRoundTripAndDeleteCleanly(callable $make): void
    {
        $store = $make($this->directory);

        $names = ['user/42:thread #1', '../../etc/passwd', 'ordine:è-123 ✓'];

        foreach ($names as $name) {
            $store->put($name, 'step-1', 'payload:' . $name);
        }

        foreach ($names as $name) {
            $this->assertSame('payload:' . $name, $store->get($name, 'step-1'));
        }

        $store->delete($names[0]);

        $this->assertNull($store->get($names[0], 'step-1'));
        $this->assertSame('payload:' . $names[1], $store->get($names[1], 'step-1'));
    }

    public function testFileBackendKeepsHostileNamesInsideItsDirectory(): void
    {
        $store = new FilePersistence($this->directory);

        $store->put('../escape-attempt', 'step-1', 'payload');

        // The record landed as the ONLY file, INSIDE the configured directory
        // — a traversal-shaped address must never write outside it.
        $entries = array_diff(scandir($this->directory) ?: [], ['.', '..']);
        $this->assertCount(1, $entries);

        // A fresh instance reading from disk (no warm cache) finds it back.
        $fresh = new FilePersistence($this->directory);
        $this->assertSame('payload', $fresh->get('../escape-attempt', 'step-1'));
    }

    public function testFileBackendThrowsOnAFailedWrite(): void
    {
        $store = new FilePersistence($this->directory);

        // A filename far beyond NAME_MAX makes the write fail loudly instead
        // of silently dropping a durable record.
        $this->expectException(\NeuronAI\Exceptions\WorkflowException::class);
        $this->expectExceptionMessage('Unable to write partition');

        $store->put(str_repeat('x', 300), 'step-1', 'payload');
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
