<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Persistence;

use NeuronAI\Workflow\Persistence\FilePersistence;
use PHPUnit\Framework\TestCase;

use function array_diff;
use function array_map;
use function array_unique;
use function array_values;
use function bin2hex;
use function count;
use function random_bytes;
use function rmdir;
use function scandir;
use function strtolower;
use function sys_get_temp_dir;
use function unlink;

class FilePersistenceCaseInsensitiveFilesystemTest extends TestCase
{
    protected string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/neuron_case_fold_' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        foreach ($this->entries() as $entry) {
            unlink($this->directory . '/' . $entry);
        }
        @rmdir($this->directory);
    }

    public function test_case_variant_partitions_map_to_file_names_distinct_on_case_insensitive_filesystems(): void
    {
        $store = new FilePersistence($this->directory);
        $partitions = ['Order:A', 'order:a', 'ORDER:A', 'Workflow', 'workflow'];

        foreach ($partitions as $partition) {
            $store->initializeIfAbsent($partition, 'control', $partition);
        }

        $caseFoldedNames = array_map(strtolower(...), $this->entries());

        // macOS (APFS/HFS+ default) and Windows (NTFS) resolve these names case-insensitively.
        $this->assertCount(count($partitions), array_unique($caseFoldedNames));
        foreach ($partitions as $partition) {
            $this->assertSame($partition, $store->get($partition, 'control'));
        }
    }

    /** @return list<string> */
    protected function entries(): array
    {
        $entries = @scandir($this->directory);

        return $entries === false ? [] : array_values(array_diff($entries, ['.', '..']));
    }
}
