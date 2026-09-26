<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Persistence;

use NeuronAI\Workflow\Persistence\FilePersistence;
use NeuronAI\Workflow\Persistence\InMemoryPersistence;
use NeuronAI\Workflow\Persistence\PersistenceInterface;
use PHPUnit\Framework\TestCase;

use function array_map;
use function bin2hex;
use function glob;
use function is_dir;
use function random_bytes;
use function rmdir;
use function sys_get_temp_dir;
use function unlink;

use const GLOB_BRACE;

class FilePersistenceNumericKeyTest extends TestCase
{
    protected string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/neuron_numeric_' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        if (is_dir($this->directory)) {
            array_map(unlink(...), glob($this->directory . '/{,.}[!.]*', GLOB_BRACE) ?: []);
            rmdir($this->directory);
        }
    }

    public function test_in_memory_backend_accepts_numeric_string_keys(): void
    {
        $this->assertNumericKeysRoundTrip(new InMemoryPersistence());
    }

    public function test_file_backend_accepts_numeric_string_keys(): void
    {
        $this->assertNumericKeysRoundTrip(new FilePersistence($this->directory));
    }

    public function test_file_backend_survives_reload_with_numeric_string_keys(): void
    {
        $this->assertTrue((new FilePersistence($this->directory))->initializeIfAbsent('workflow', '0', 'owner', ['42' => 'step']));

        $reloaded = new FilePersistence($this->directory);
        $this->assertTrue($reloaded->writeIfUnchanged('workflow', '0', 'owner', ['7' => 'next']));
        $this->assertSame('step', $reloaded->get('workflow', '42'));
        $this->assertSame('next', $reloaded->get('workflow', '7'));
        $this->assertTrue($reloaded->deleteIfUnchanged('workflow', '0', 'owner'));
    }

    protected function assertNumericKeysRoundTrip(PersistenceInterface $store): void
    {
        $this->assertTrue($store->initializeIfAbsent('workflow', '0', 'owner', ['42' => 'step']));
        $this->assertSame('owner', $store->get('workflow', '0'));
        $this->assertSame('step', $store->get('workflow', '42'));
        $this->assertTrue($store->writeIfUnchanged('workflow', '0', 'owner', ['42' => 'updated']));
        $this->assertSame('updated', $store->get('workflow', '42'));
    }
}
