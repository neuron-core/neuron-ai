<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Evaluation\Cache;

use NeuronAI\Evaluation\Cache\FileEvaluationCache;
use PHPUnit\Framework\TestCase;

use function file_exists;
use function mkdir;
use function rmdir;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

class FileEvaluationCacheKeyConfinementTest extends TestCase
{
    public function test_a_key_cannot_write_outside_the_cache_directory(): void
    {
        $root = sys_get_temp_dir() . '/neuron-eval-root-' . uniqid();
        mkdir($root . '/cache', 0o777, true);
        $escaped = $root . '/escaped.cache';

        try {
            (new FileEvaluationCache($root . '/cache'))->set('../escaped', 'payload');

            $this->assertFileDoesNotExist($escaped);
        } finally {
            if (file_exists($escaped)) {
                unlink($escaped);
            }
            rmdir($root . '/cache');
            rmdir($root);
        }
    }
}
