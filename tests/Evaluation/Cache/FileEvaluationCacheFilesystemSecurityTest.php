<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Evaluation\Cache;

use NeuronAI\Evaluation\Cache\FileEvaluationCache;
use NeuronAI\Tests\Support\FileSystemSandbox;
use PHPUnit\Framework\TestCase;

use function file_get_contents;
use function file_put_contents;
use function is_link;
use function mkdir;

use const PHP_OS_FAMILY;

/**
 * The cache directory is meant to be kept between runs and in CI, where a
 * restored or shared checkout may contain symlinks nobody reviewed.
 */
class FileEvaluationCacheFilesystemSecurityTest extends TestCase
{
    use FileSystemSandbox;

    protected string $base;

    protected string $directory;

    protected function setUp(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('Symlinks need elevated privileges on Windows.');
        }

        $this->base = $this->createSandbox('neuron_eval_cache');
        $this->directory = $this->base . '/cache';
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            $this->removeSandbox($this->base);
        }
    }

    public function test_a_write_replaces_a_planted_symlink_instead_of_writing_through_it(): void
    {
        $outside = $this->base . '/composer.json';
        file_put_contents($outside, '{"name": "victim"}');
        $this->symlinkOrSkip($outside, $this->directory . '/key.cache');

        $cache = new FileEvaluationCache($this->directory);
        $cache->set('key', 'agent output');

        $this->assertFalse(is_link($this->directory . '/key.cache'));
        $this->assertSame('{"name": "victim"}', file_get_contents($outside));
        $this->assertSame('agent output', $cache->get('key'));
    }
}
