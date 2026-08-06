<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Evaluation\Cache;

use NeuronAI\Evaluation\Cache\FileEvaluationCache;
use PHPUnit\Framework\TestCase;

use function array_map;
use function glob;
use function is_dir;
use function rmdir;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

class FileEvaluationCacheTest extends TestCase
{
    protected string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/neuron-eval-cache-' . uniqid();
    }

    protected function tearDown(): void
    {
        if (is_dir($this->directory)) {
            array_map(unlink(...), glob($this->directory . '/*') ?: []);
            rmdir($this->directory);
        }
    }

    public function testMissingKeyIsAbsent(): void
    {
        $cache = new FileEvaluationCache($this->directory);

        $this->assertFalse($cache->has('missing'));
        $this->assertNull($cache->get('missing'));
    }

    public function testSetGetRoundTrip(): void
    {
        $cache = new FileEvaluationCache($this->directory);

        $cache->set('key1', 'a string output');
        $cache->set('key2', ['nested' => ['data' => 42]]);

        $this->assertTrue($cache->has('key1'));
        $this->assertSame('a string output', $cache->get('key1'));
        $this->assertSame(['nested' => ['data' => 42]], $cache->get('key2'));
    }

    public function testOverwriteReplacesValue(): void
    {
        $cache = new FileEvaluationCache($this->directory);

        $cache->set('key', 'first');
        $cache->set('key', 'second');

        $this->assertSame('second', $cache->get('key'));
    }

    public function testNonSerializableValueIsSilentlySkipped(): void
    {
        $cache = new FileEvaluationCache($this->directory);

        $cache->set('key', fn (): string => 'not serializable');

        $this->assertFalse($cache->has('key'));
    }

}
