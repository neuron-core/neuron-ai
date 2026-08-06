<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Evaluation\Cache;

use NeuronAI\Evaluation\Cache\CacheKey;
use NeuronAI\Evaluation\EvaluationException;
use NeuronAI\Tests\Evaluation\Cache\Fixtures\DependentEvaluator;
use NeuronAI\Tests\Evaluation\Cache\Fixtures\DifferentRunEvaluator;
use NeuronAI\Tests\Evaluation\Cache\Fixtures\IdenticalRunEvaluatorA;
use NeuronAI\Tests\Evaluation\Cache\Fixtures\IdenticalRunEvaluatorB;
use PHPUnit\Framework\TestCase;

use function file_put_contents;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

class CacheKeyTest extends TestCase
{
    protected function tearDown(): void
    {
        DependentEvaluator::$dependencies = [];
    }

    public function testKeyIsStableForSameEvaluatorAndItem(): void
    {
        $evaluator = new IdenticalRunEvaluatorA();
        $item = ['input' => 'hello'];

        $this->assertSame(
            CacheKey::make($evaluator, $item),
            CacheKey::make($evaluator, $item)
        );
    }

    public function testDifferentItemsProduceDifferentKeys(): void
    {
        $evaluator = new IdenticalRunEvaluatorA();

        $this->assertNotSame(
            CacheKey::make($evaluator, ['input' => 'hello']),
            CacheKey::make($evaluator, ['input' => 'goodbye'])
        );
    }

    public function testRunMethodHashIgnoresEvaluateChanges(): void
    {
        // A and B have byte-identical run() bodies but different evaluate()
        // implementations: the run fingerprint must be the same
        $this->assertSame(
            CacheKey::runMethodHash(new IdenticalRunEvaluatorA()),
            CacheKey::runMethodHash(new IdenticalRunEvaluatorB())
        );
    }

    public function testRunMethodHashChangesWithRunBody(): void
    {
        $this->assertNotSame(
            CacheKey::runMethodHash(new IdenticalRunEvaluatorA()),
            CacheKey::runMethodHash(new DifferentRunEvaluator())
        );
    }

    public function testDependencyContentChangesTheKey(): void
    {
        $depFile = tempnam(sys_get_temp_dir(), 'neuron-dep');
        $this->assertNotFalse($depFile);
        file_put_contents($depFile, 'You are a helpful assistant.');
        DependentEvaluator::$dependencies = [$depFile];

        $evaluator = new DependentEvaluator();
        $item = ['input' => 'hello'];

        $before = CacheKey::make($evaluator, $item);

        file_put_contents($depFile, 'You are a VERY helpful assistant.');
        $after = CacheKey::make($evaluator, $item);

        unlink($depFile);

        $this->assertNotSame($before, $after);
    }

    public function testClassStringDependencyIsResolvedToItsSourceFile(): void
    {
        DependentEvaluator::$dependencies = [IdenticalRunEvaluatorA::class];

        $key = CacheKey::make(new DependentEvaluator(), ['input' => 'hello']);

        $this->assertNotNull($key);
    }

    public function testUnresolvableDependencyThrows(): void
    {
        DependentEvaluator::$dependencies = ['/path/that/does/not/exist.php'];

        $this->expectException(EvaluationException::class);

        CacheKey::make(new DependentEvaluator(), ['input' => 'hello']);
    }

    public function testNonSerializableItemReturnsNull(): void
    {
        $evaluator = new IdenticalRunEvaluatorA();

        $this->assertNull(CacheKey::make($evaluator, ['callback' => fn (): string => 'x']));
    }
}
