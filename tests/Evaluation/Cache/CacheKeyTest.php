<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Evaluation\Cache;

use NeuronAI\Evaluation\Cache\CacheKey;
use NeuronAI\Evaluation\EvaluationException;
use NeuronAI\Tests\Evaluation\Cache\Stub\DependentEvaluator;
use NeuronAI\Tests\Evaluation\Cache\Stub\DifferentRunEvaluator;
use NeuronAI\Tests\Evaluation\Cache\Stub\IdenticalRunEvaluatorA;
use NeuronAI\Tests\Evaluation\Cache\Stub\IdenticalRunEvaluatorB;
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

    public function test_key_is_stable_for_same_evaluator_and_item(): void
    {
        $evaluator = new IdenticalRunEvaluatorA();
        $item = ['input' => 'hello'];

        $this->assertSame(
            CacheKey::make($evaluator, $item),
            CacheKey::make($evaluator, $item)
        );
    }

    public function test_different_items_produce_different_keys(): void
    {
        $evaluator = new IdenticalRunEvaluatorA();

        $this->assertNotSame(
            CacheKey::make($evaluator, ['input' => 'hello']),
            CacheKey::make($evaluator, ['input' => 'goodbye'])
        );
    }

    public function test_run_method_hash_ignores_evaluate_changes(): void
    {
        // A and B have byte-identical run() bodies but different evaluate()
        // implementations: the run fingerprint must be the same
        $this->assertSame(
            CacheKey::runMethodHash(new IdenticalRunEvaluatorA()),
            CacheKey::runMethodHash(new IdenticalRunEvaluatorB())
        );
    }

    public function test_run_method_hash_changes_with_run_body(): void
    {
        $this->assertNotSame(
            CacheKey::runMethodHash(new IdenticalRunEvaluatorA()),
            CacheKey::runMethodHash(new DifferentRunEvaluator())
        );
    }

    public function test_dependency_content_changes_the_key(): void
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

    public function test_class_string_dependency_is_resolved_to_its_source_file(): void
    {
        DependentEvaluator::$dependencies = [IdenticalRunEvaluatorA::class];

        $key = CacheKey::make(new DependentEvaluator(), ['input' => 'hello']);

        $this->assertNotNull($key);
    }

    public function test_unresolvable_dependency_throws(): void
    {
        DependentEvaluator::$dependencies = ['/path/that/does/not/exist.php'];

        $this->expectException(EvaluationException::class);

        CacheKey::make(new DependentEvaluator(), ['input' => 'hello']);
    }

    public function test_non_serializable_item_returns_null(): void
    {
        $evaluator = new IdenticalRunEvaluatorA();

        $this->assertNull(CacheKey::make($evaluator, ['callback' => fn (): string => 'x']));
    }
}
