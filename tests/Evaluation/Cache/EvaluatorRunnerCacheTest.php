<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Evaluation\Cache;

use NeuronAI\Evaluation\Cache\FileEvaluationCache;
use NeuronAI\Evaluation\Runner\EvaluatorRunner;
use NeuronAI\Tests\Evaluation\Cache\Fixtures\CountingEvaluator;
use NeuronAI\Tests\Evaluation\Cache\Fixtures\NonSerializableOutputEvaluator;
use PHPUnit\Framework\TestCase;

use function array_map;
use function glob;
use function is_dir;
use function rmdir;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

class EvaluatorRunnerCacheTest extends TestCase
{
    protected string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/neuron-eval-cache-' . uniqid();
        CountingEvaluator::$runCalls = 0;
        CountingEvaluator::$evaluateCalls = 0;
        NonSerializableOutputEvaluator::$runCalls = 0;
    }

    protected function tearDown(): void
    {
        if (is_dir($this->directory)) {
            array_map(unlink(...), glob($this->directory . '/*') ?: []);
            rmdir($this->directory);
        }
    }

    public function testSecondRunServesFromCacheAndStillEvaluates(): void
    {
        $cache = new FileEvaluationCache($this->directory);

        $first = (new EvaluatorRunner($cache))->run(new CountingEvaluator());

        $this->assertSame(2, CountingEvaluator::$runCalls);
        $this->assertSame(0, $first->getCachedRunCount());

        $second = (new EvaluatorRunner($cache))->run(new CountingEvaluator());

        // run() not called again, but evaluate() executed for every item on both runs
        $this->assertSame(2, CountingEvaluator::$runCalls);
        $this->assertSame(4, CountingEvaluator::$evaluateCalls);
        $this->assertSame(2, $second->getCachedRunCount());

        foreach ($second->getResults() as $result) {
            $this->assertTrue($result->isCachedRun());
            $this->assertTrue($result->isPassed());
        }
    }

    public function testRefreshBypassesCacheReads(): void
    {
        $cache = new FileEvaluationCache($this->directory);

        (new EvaluatorRunner($cache))->run(new CountingEvaluator());
        $summary = (new EvaluatorRunner($cache, refresh: true))->run(new CountingEvaluator());

        $this->assertSame(4, CountingEvaluator::$runCalls);
        $this->assertSame(0, $summary->getCachedRunCount());
    }

    public function testWithoutCacheEveryRunExecutes(): void
    {
        (new EvaluatorRunner())->run(new CountingEvaluator());
        $summary = (new EvaluatorRunner())->run(new CountingEvaluator());

        $this->assertSame(4, CountingEvaluator::$runCalls);
        $this->assertSame(0, $summary->getCachedRunCount());
    }

    public function testNonSerializableOutputIsNeverCached(): void
    {
        $cache = new FileEvaluationCache($this->directory);

        $first = (new EvaluatorRunner($cache))->run(new NonSerializableOutputEvaluator());
        $second = (new EvaluatorRunner($cache))->run(new NonSerializableOutputEvaluator());

        $this->assertSame(2, NonSerializableOutputEvaluator::$runCalls);
        $this->assertSame(0, $second->getCachedRunCount());
        $this->assertTrue($first->getResults()[0]->isPassed());
        $this->assertTrue($second->getResults()[0]->isPassed());
    }
}
