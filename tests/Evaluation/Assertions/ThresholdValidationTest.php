<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Evaluation\Assertions;

use Closure;
use InvalidArgumentException;
use NeuronAI\Agent\Agent;
use NeuronAI\Evaluation\Assertions\AgentJudge;
use NeuronAI\Evaluation\Assertions\Judges\HelpfulnessJudge;
use NeuronAI\Evaluation\Assertions\StringDistance;
use NeuronAI\Evaluation\Assertions\StringSimilarity;
use NeuronAI\Testing\FakeEmbeddingsProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use const INF;
use const NAN;

/**
 * Scores are compared with >=, so a threshold outside [0, 1] would make an
 * assertion that can never pass, or one that always does.
 */
class ThresholdValidationTest extends TestCase
{
    /**
     * @return iterable<string, array{Closure(float): mixed}>
     */
    public static function assertions(): iterable
    {
        yield 'AgentJudge' => [fn (float $threshold): AgentJudge => new AgentJudge(Agent::make(), 'Be correct', $threshold)];
        yield 'judge subclass' => [fn (float $threshold): HelpfulnessJudge => new HelpfulnessJudge(Agent::make(), $threshold)];
        yield 'StringDistance' => [fn (float $threshold): StringDistance => new StringDistance('reference', $threshold)];
        yield 'StringSimilarity' => [fn (float $threshold): StringSimilarity => new StringSimilarity('reference', new FakeEmbeddingsProvider(), $threshold)];
    }

    /**
     * @return iterable<string, array{Closure(float): mixed, float}>
     */
    public static function invalidThresholds(): iterable
    {
        foreach (self::assertions() as $name => [$make]) {
            foreach (['negative' => -0.1, 'percent' => 70.0, 'above one' => 1.5, 'NAN' => NAN, 'INF' => INF, '-INF' => -INF] as $label => $threshold) {
                yield "{$name}, {$label}" => [$make, $threshold];
            }
        }
    }

    #[DataProvider('invalidThresholds')]
    public function test_an_out_of_range_threshold_is_rejected(Closure $make, float $threshold): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Threshold must be finite and between zero and one.');

        $make($threshold);
    }

    /**
     * @return iterable<string, array{Closure(float): mixed, float}>
     */
    public static function boundaryThresholds(): iterable
    {
        foreach (self::assertions() as $name => [$make]) {
            yield "{$name}, zero" => [$make, 0.0];
            yield "{$name}, one" => [$make, 1.0];
        }
    }

    #[DataProvider('boundaryThresholds')]
    public function test_the_bounds_are_valid_thresholds(Closure $make, float $threshold): void
    {
        $this->assertIsObject($make($threshold));
    }
}
