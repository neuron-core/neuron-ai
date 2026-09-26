<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Evaluation\Cache;

use NeuronAI\Evaluation\Cache\CacheKey;
use NeuronAI\Evaluation\Cache\FileEvaluationCache;
use NeuronAI\Evaluation\Runner\EvaluatorRunner;
use NeuronAI\Tests\Evaluation\Cache\Stub\CountingEvaluator;
use PHPUnit\Framework\TestCase;

use function array_map;
use function file_get_contents;
use function file_put_contents;
use function glob;
use function mkdir;
use function rmdir;
use function serialize;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

class CorruptedCacheEntryTest extends TestCase
{
    protected string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/neuron-eval-cache-' . uniqid();
        mkdir($this->directory);
        CountingEvaluator::$runCalls = 0;
        CountingEvaluator::$evaluateCalls = 0;
    }

    protected function tearDown(): void
    {
        array_map(unlink(...), glob($this->directory . '/*') ?: []);
        rmdir($this->directory);
    }

    public function test_a_corrupted_entry_is_a_cache_miss(): void
    {
        // e.g. a truncated file from a disk-full write or a partially restored CI cache
        $evaluator = new CountingEvaluator();
        $paths = [];
        foreach ($evaluator->getDataset()->load() as $item) {
            $paths[] = $path = $this->directory . '/' . CacheKey::make($evaluator, $item) . '.cache';
            file_put_contents($path, 's:20:"trunc');
        }

        $results = (new EvaluatorRunner(new FileEvaluationCache($this->directory)))->run($evaluator);

        $this->assertSame(2, CountingEvaluator::$runCalls);
        $this->assertSame(0, $results->getCachedRunCount());
        $this->assertTrue($results->getResults()[0]->isPassed());
        $this->assertSame(serialize('output: alpha'), file_get_contents($paths[0]));
    }

    public function test_a_legitimately_cached_false_is_still_a_hit(): void
    {
        $cache = new FileEvaluationCache($this->directory);
        $cache->set('key', false);

        $this->assertTrue($cache->has('key'));
        $this->assertFalse($cache->get('key'));
    }
}
