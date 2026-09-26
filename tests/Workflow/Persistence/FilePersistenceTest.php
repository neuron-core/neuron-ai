<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Persistence;

use NeuronAI\Exceptions\PersistenceException;
use NeuronAI\Exceptions\WorkflowException;
use NeuronAI\Workflow\Persistence\FilePersistence;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

use function array_diff;
use function array_values;
use function base64_encode;
use function bin2hex;
use function chmod;
use function file_get_contents;
use function file_put_contents;
use function fileperms;
use function function_exists;
use function is_dir;
use function is_link;
use function json_decode;
use function json_encode;
use function mkdir;
use function posix_geteuid;
use function random_bytes;
use function rmdir;
use function scandir;
use function str_repeat;
use function strlen;
use function substr;
use function symlink;
use function sys_get_temp_dir;
use function unlink;

use const JSON_THROW_ON_ERROR;
use const PHP_OS_FAMILY;

class FilePersistenceTest extends TestCase
{
    protected string $root;

    protected string $directory;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/neuron_file_store_' . bin2hex(random_bytes(6));
        $this->directory = $this->root . '/store';
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->root)) {
            return;
        }

        $entries = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->root, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($entries as $entry) {
            $entry->isDir() && !$entry->isLink() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($this->root);
    }

    /** @return list<string> */
    protected function entries(string $directory): array
    {
        return array_values(array_diff(scandir($directory) ?: [], ['.', '..']));
    }

    /** @return array<string, array{string}> */
    public static function hostilePartitionProvider(): array
    {
        return [
            'parent traversal' => ['../escape'],
            'deep traversal' => ['../../../../etc/passwd'],
            'dot dot' => ['..'],
            'dot' => ['.'],
            'absolute path' => ['/etc/passwd'],
            'windows separator' => ['..\\..\\escape'],
            'null byte' => ["safe\0/../../escape"],
            'encoded separator' => ['..%2F..%2Fescape'],
            'newline' => ["line\n../escape"],
        ];
    }

    #[DataProvider('hostilePartitionProvider')]
    public function test_a_hostile_partition_name_is_stored_as_one_file_inside_the_directory(string $partition): void
    {
        $store = new FilePersistence($this->directory);

        $this->assertTrue($store->initializeIfAbsent($partition, '__control', 'owner', ['step' => 'payload']));

        $this->assertSame(['store'], $this->entries($this->root));
        $entries = $this->entries($this->directory);
        $this->assertCount(1, $entries);
        $this->assertStringEndsWith('.store', $entries[0]);
        $this->assertSame('payload', (new FilePersistence($this->directory))->get($partition, 'step'));
        $this->assertTrue($store->deleteIfUnchanged($partition, '__control', 'owner'));
        $this->assertSame([], $this->entries($this->directory));
    }

    public function test_construction_and_reads_perform_no_writes(): void
    {
        $store = new FilePersistence($this->directory);

        $this->assertNull($store->get('workflow', '__control'));
        $this->assertFalse($store->writeIfUnchanged('workflow', '__control', 'owner', ['step' => 'rejected']));
        $this->assertFalse($store->deleteIfUnchanged('workflow', '__control', 'owner'));
        $this->assertDirectoryDoesNotExist($this->root);
    }

    public function test_the_directory_is_created_on_the_first_write(): void
    {
        $store = new FilePersistence($this->directory . '/nested');

        $this->assertTrue($store->initializeIfAbsent('workflow', '__control', 'owner'));

        $this->assertDirectoryExists($this->directory . '/nested');
        $this->assertSame('owner', $store->get('workflow', '__control'));
    }

    public function test_storage_is_readable_by_its_owner_only(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('POSIX permission bits are not available on Windows.');
        }

        $store = new FilePersistence($this->directory);
        $store->initializeIfAbsent('workflow', '__control', 'owner');

        $this->assertSame(0, fileperms($this->directory) & 0o077);
        $this->assertSame(0, fileperms($this->directory . '/workflow.store') & 0o077);
    }

    public function test_an_uncreatable_directory_fails_loudly(): void
    {
        mkdir($this->root, 0o700);
        file_put_contents($this->directory, 'a file where the directory should be');

        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage("Unable to create directory '{$this->directory}/nested'");

        (new FilePersistence($this->directory . '/nested'))->initializeIfAbsent('workflow', '__control', 'owner');
    }

    protected function requirePermissionChecks(): void
    {
        if (PHP_OS_FAMILY === 'Windows' || !function_exists('posix_geteuid') || posix_geteuid() === 0) {
            $this->markTestSkipped('POSIX permission checks need a non-root user.');
        }
    }

    public function test_an_unreadable_partition_fails_loudly_instead_of_reading_as_absent(): void
    {
        $this->requirePermissionChecks();
        $store = new FilePersistence($this->directory);
        $store->initializeIfAbsent('workflow', '__control', 'owner');
        chmod($this->directory . '/workflow.store', 0o000);

        try {
            $this->expectException(PersistenceException::class);
            $this->expectExceptionMessage("Unable to read Workflow partition at '{$this->directory}/workflow.store'.");

            $store->get('workflow', '__control');
        } finally {
            chmod($this->directory . '/workflow.store', 0o600);
        }
    }

    public function test_a_read_only_directory_fails_writes_and_deletes_loudly(): void
    {
        $this->requirePermissionChecks();
        $store = new FilePersistence($this->directory);
        $store->initializeIfAbsent('workflow', '__control', 'owner', ['step' => 'first']);
        chmod($this->directory, 0o500);

        try {
            foreach ([
                'Unable to write partition' => fn (): bool => $store->writeIfUnchanged('workflow', '__control', 'owner', ['step' => 'second']),
                'Unable to delete partition' => fn (): bool => $store->deleteIfUnchanged('workflow', '__control', 'owner'),
            ] as $message => $operation) {
                try {
                    $operation();
                    $this->fail("Expected: {$message}");
                } catch (WorkflowException $e) {
                    $this->assertStringStartsWith("{$message} 'workflow'", $e->getMessage());
                }
            }
            $this->assertSame('first', $store->get('workflow', 'step'));
            $this->assertSame(['workflow.store'], $this->entries($this->directory));
        } finally {
            chmod($this->directory, 0o700);
        }
    }

    public function test_a_failed_write_throws_and_leaves_no_partial_or_temporary_file(): void
    {
        $store = new FilePersistence($this->directory);
        $partition = str_repeat('x', 300);

        try {
            $store->initializeIfAbsent($partition, '__control', 'owner');
            $this->fail('The oversized partition filename should fail.');
        } catch (WorkflowException $e) {
            $this->assertStringContainsString("Unable to write partition '{$partition}'", $e->getMessage());
        }

        $this->assertNull($store->get($partition, '__control'));
        $this->assertSame([], $this->entries($this->directory));
    }

    public function test_successful_writes_leave_only_partition_files(): void
    {
        $store = new FilePersistence($this->directory);
        $store->initializeIfAbsent('first', '__control', 'owner', ['step' => 'result']);
        $store->writeIfUnchanged('first', '__control', 'owner', ['__control' => 'next']);
        $store->initializeIfAbsent('second', '__control', 'owner');

        $this->assertSame(['first.store', 'second.store'], $this->entries($this->directory));
    }

    public function test_partitions_are_versioned_envelopes_of_base64_records(): void
    {
        $store = new FilePersistence($this->directory);
        $store->initializeIfAbsent('order:1', '__control', "owner\0\xFF", ['step' => 'result']);

        $contents = json_decode(
            file_get_contents($this->directory . '/order%3A1.store'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        $this->assertSame([
            'version' => 2,
            'records' => [
                base64_encode('step') => base64_encode('result'),
                base64_encode('__control') => base64_encode("owner\0\xFF"),
            ],
        ], $contents);
    }

    public function test_a_stale_instance_cannot_overwrite_a_newer_write(): void
    {
        $first = new FilePersistence($this->directory);
        $first->initializeIfAbsent('workflow', '__control', 'attempt-1');

        $second = new FilePersistence($this->directory);
        $this->assertTrue($second->writeIfUnchanged('workflow', '__control', 'attempt-1', [
            '__control' => 'attempt-2',
        ]));

        $this->assertFalse($first->writeIfUnchanged('workflow', '__control', 'attempt-1', [
            'step' => 'must-not-land',
        ]));
        $this->assertFalse($first->deleteIfUnchanged('workflow', '__control', 'attempt-1'));
        $this->assertSame('attempt-2', $first->get('workflow', '__control'));
        $this->assertNull($first->get('workflow', 'step'));
    }

    public function test_a_legacy_json_map_is_read_and_upgraded_on_the_next_write(): void
    {
        mkdir($this->directory, 0o700, true);
        file_put_contents($this->directory . '/workflow.store', json_encode([
            '__control' => 'attempt-1',
            'step' => 'result',
        ], JSON_THROW_ON_ERROR));
        $store = new FilePersistence($this->directory);

        $this->assertSame('attempt-1', $store->get('workflow', '__control'));
        $this->assertSame('result', $store->get('workflow', 'step'));

        $this->assertTrue($store->writeIfUnchanged('workflow', '__control', 'attempt-1', ['__control' => 'attempt-2']));
        $contents = json_decode(file_get_contents($this->directory . '/workflow.store'), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(2, $contents['version']);
        $this->assertSame('result', $store->get('workflow', 'step'));
        $this->assertSame('attempt-2', $store->get('workflow', '__control'));
    }

    /** @return array<string, array{string, string}> */
    public static function corruptedPartitionProvider(): array
    {
        $valid = json_encode(['version' => 2, 'records' => [base64_encode('step') => base64_encode('result')]]);

        return [
            'invalid json' => ['{invalid', 'Corrupted Workflow partition'],
            'empty file' => ['', 'Corrupted Workflow partition'],
            'truncated envelope' => [substr($valid, 0, strlen($valid) - 3), 'Corrupted Workflow partition'],
            'json scalar' => ['"a string"', 'expected a JSON object'],
            'json list' => ['["a","b"]', 'every key and value must be a string'],
            'legacy non-string value' => ['{"step":1}', 'every key and value must be a string'],
            'nested legacy value' => ['{"step":{"a":"b"}}', 'every key and value must be a string'],
            'envelope without records' => ['{"version":2,"records":"none"}', 'every key and value must be a string'],
            'unknown envelope version' => ['{"version":3,"records":{"c3RlcA==":"cmVzdWx0"}}', 'Corrupted Workflow partition'],
            'string envelope version' => ['{"version":"2","records":{"c3RlcA==":"cmVzdWx0"}}', 'Corrupted Workflow partition'],
            'invalid base64 key' => ['{"version":2,"records":{"***":"cmVzdWx0"}}', 'invalid encoded record'],
            'invalid base64 value' => ['{"version":2,"records":{"c3RlcA==":"***"}}', 'invalid encoded record'],
            'non-string encoded value' => ['{"version":2,"records":{"c3RlcA==":42}}', 'invalid encoded record'],
        ];
    }

    #[DataProvider('corruptedPartitionProvider')]
    public function test_a_corrupted_partition_is_reported_instead_of_read_as_absent(string $contents, string $message): void
    {
        mkdir($this->directory, 0o700, true);
        file_put_contents($this->directory . '/corrupt.store', $contents);

        $this->expectException(PersistenceException::class);
        $this->expectExceptionMessage($message);

        (new FilePersistence($this->directory))->get('corrupt', 'step');
    }

    #[DataProvider('corruptedPartitionProvider')]
    public function test_a_corrupted_partition_is_never_overwritten_by_a_conditional_write(string $contents): void
    {
        mkdir($this->directory, 0o700, true);
        file_put_contents($this->directory . '/corrupt.store', $contents);
        $store = new FilePersistence($this->directory);

        foreach ([
            fn (): bool => $store->initializeIfAbsent('corrupt', '__control', 'owner'),
            fn (): bool => $store->writeIfUnchanged('corrupt', '__control', 'owner', ['step' => 'new']),
            fn (): bool => $store->deleteIfUnchanged('corrupt', '__control', 'owner'),
        ] as $operation) {
            try {
                $operation();
                $this->fail('A corrupted partition must fail the operation.');
            } catch (PersistenceException) {
                $this->assertSame($contents, file_get_contents($this->directory . '/corrupt.store'));
            }
        }
    }

    public function test_a_write_replaces_a_planted_symlink_instead_of_writing_through_it(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('Symlinks need elevated privileges on Windows.');
        }

        mkdir($this->directory, 0o700, true);
        $outside = $this->root . '/outside.json';
        $original = json_encode(['version' => 2, 'records' => [base64_encode('__control') => base64_encode('owner')]]);
        file_put_contents($outside, $original);
        symlink($outside, $this->directory . '/workflow.store');

        $store = new FilePersistence($this->directory);
        $this->assertTrue($store->writeIfUnchanged('workflow', '__control', 'owner', ['step' => 'result']));

        $this->assertFalse(is_link($this->directory . '/workflow.store'));
        $this->assertSame($original, file_get_contents($outside));
        $this->assertSame('result', $store->get('workflow', 'step'));
    }

    public function test_delete_removes_the_partition_file(): void
    {
        $store = new FilePersistence($this->directory);
        $store->initializeIfAbsent('workflow', '__control', 'owner');
        $store->initializeIfAbsent('other', '__control', 'owner');

        $this->assertTrue($store->deleteIfUnchanged('workflow', '__control', 'owner'));

        $this->assertSame(['other.store'], $this->entries($this->directory));
        $this->assertSame('owner', $store->get('other', '__control'));
    }
}
