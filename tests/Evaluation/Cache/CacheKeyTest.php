<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Evaluation\Cache;

use NeuronAI\Evaluation\AssertionOutcomes;
use NeuronAI\Evaluation\Cache\CacheKey;
use NeuronAI\Evaluation\Contracts\DatasetInterface;
use NeuronAI\Evaluation\Contracts\EvaluatorInterface;
use NeuronAI\Evaluation\Dataset\ArrayDataset;
use NeuronAI\Evaluation\EvaluationException;
use NeuronAI\Tests\Evaluation\Cache\Stub\DependentEvaluator;
use NeuronAI\Tests\Evaluation\Cache\Stub\DifferentRunEvaluator;
use NeuronAI\Tests\Evaluation\Cache\Stub\IdenticalRunEvaluatorA;
use NeuronAI\Tests\Evaluation\Cache\Stub\IdenticalRunEvaluatorB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

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
        $item = ['input' => 'hello'];
        $withoutDependency = CacheKey::make(new DependentEvaluator(), $item);

        DependentEvaluator::$dependencies = [IdenticalRunEvaluatorA::class];
        $withFirstClass = CacheKey::make(new DependentEvaluator(), $item);

        DependentEvaluator::$dependencies = [DifferentRunEvaluator::class];
        $withSecondClass = CacheKey::make(new DependentEvaluator(), $item);

        $this->assertNotSame($withoutDependency, $withFirstClass);
        $this->assertNotSame($withFirstClass, $withSecondClass);
    }

    public function test_class_and_file_dependency_on_the_same_source_are_equivalent(): void
    {
        $item = ['input' => 'hello'];

        DependentEvaluator::$dependencies = [IdenticalRunEvaluatorA::class];
        $byClass = CacheKey::make(new DependentEvaluator(), $item);

        DependentEvaluator::$dependencies = [(string) (new ReflectionClass(IdenticalRunEvaluatorA::class))->getFileName()];
        $byFile = CacheKey::make(new DependentEvaluator(), $item);

        $this->assertSame($byClass, $byFile);
    }

    public function test_unresolvable_dependency_throws(): void
    {
        DependentEvaluator::$dependencies = ['/path/that/does/not/exist.php'];

        $this->expectException(EvaluationException::class);
        $this->expectExceptionMessage(
            "Cache dependency '/path/that/does/not/exist.php' of " . DependentEvaluator::class
            . ' does not resolve to a readable source file.'
        );

        CacheKey::make(new DependentEvaluator(), ['input' => 'hello']);
    }

    public function test_non_serializable_item_returns_null(): void
    {
        $evaluator = new IdenticalRunEvaluatorA();

        $this->assertNull(CacheKey::make($evaluator, ['callback' => fn (): string => 'x']));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unfingerprintableDependencies(): iterable
    {
        yield 'internal class without a source file' => ['stdClass'];
        yield 'directory' => [__DIR__];
        yield 'empty string' => [''];
    }

    #[DataProvider('unfingerprintableDependencies')]
    public function test_dependency_without_a_readable_source_file_throws(string $dependency): void
    {
        DependentEvaluator::$dependencies = [$dependency];

        $this->expectException(EvaluationException::class);
        $this->expectExceptionMessage("Cache dependency '{$dependency}' of " . DependentEvaluator::class);

        CacheKey::make(new DependentEvaluator(), ['input' => 'hello']);
    }

    public function test_key_is_a_filesystem_safe_sha256_hex_digest(): void
    {
        $key = CacheKey::make(new IdenticalRunEvaluatorA(), ['input' => "../../etc/passwd\0<script>"]);

        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $key);
    }

    public function test_evaluator_class_is_part_of_the_key_even_with_identical_run_bodies(): void
    {
        $item = ['input' => 'hello'];

        $this->assertNotSame(
            CacheKey::make(new IdenticalRunEvaluatorA(), $item),
            CacheKey::make(new IdenticalRunEvaluatorB(), $item)
        );
    }

    public function test_run_body_is_part_of_the_key(): void
    {
        $item = ['input' => 'hello'];

        $this->assertNotSame(
            CacheKey::make(new IdenticalRunEvaluatorA(), $item),
            CacheKey::make(new DifferentRunEvaluator(), $item)
        );
    }

    /**
     * @return iterable<string, array{array<string, mixed>, array<string, mixed>}>
     */
    public static function distinctItems(): iterable
    {
        yield 'int vs numeric string' => [['n' => 1], ['n' => '1']];
        yield 'int vs float' => [['n' => 1], ['n' => 1.0]];
        yield 'bool vs int' => [['n' => true], ['n' => 1]];
        yield 'null vs empty string' => [['n' => null], ['n' => '']];
        yield 'missing vs null key' => [[], ['n' => null]];
        yield 'nested value' => [['turns' => ['a', ['b' => 1]]], ['turns' => ['a', ['b' => 2]]]];
        yield 'separator inside values' => [['a' => 'x|y', 'b' => 'z'], ['a' => 'x', 'b' => 'y|z']];
        yield 'unicode normalization forms' => [['text' => "caf\u{00E9}"], ['text' => "cafe\u{0301}"]];
        yield 'trailing whitespace' => [['text' => 'hello'], ['text' => 'hello ']];
    }

    #[DataProvider('distinctItems')]
    public function test_different_item_contents_never_share_a_key(array $first, array $second): void
    {
        $evaluator = new IdenticalRunEvaluatorA();

        $this->assertNotSame(CacheKey::make($evaluator, $first), CacheKey::make($evaluator, $second));
    }

    public function test_evaluator_outside_the_base_class_is_fingerprinted_without_dependencies(): void
    {
        $evaluator = new class () implements EvaluatorInterface {
            public function namespace(): ?string
            {
                return null;
            }

            public function setUp(): void
            {
            }

            public function getDataset(): DatasetInterface
            {
                return new ArrayDataset([]);
            }

            public function run(array $datasetItem): mixed
            {
                return $datasetItem;
            }

            public function performEvaluation(mixed $output, array $datasetItem): AssertionOutcomes
            {
                return new AssertionOutcomes(0, 0);
            }
        };

        $key = CacheKey::make($evaluator, ['input' => 'hello']);

        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $key);
        $this->assertSame($key, CacheKey::make($evaluator, ['input' => 'hello']));
    }
}
