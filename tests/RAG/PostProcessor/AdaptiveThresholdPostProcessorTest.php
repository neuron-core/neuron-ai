<?php

declare(strict_types=1);

namespace NeuronAI\Tests\RAG\PostProcessor;

use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\PostProcessor\AdaptiveThresholdPostProcessor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function array_map;

/**
 * Scores are binary fractions so every median, MAD and threshold below is
 * exact: threshold = max(0, median - multiplier * MAD), inclusive.
 */
class AdaptiveThresholdPostProcessorTest extends TestCase
{
    /**
     * @param float[] $scores
     * @return Document[]
     */
    protected function scored(array $scores): array
    {
        return array_map(
            static fn (float $score): Document => (new Document("Score {$score}"))->setScore($score),
            $scores,
        );
    }

    /**
     * @param Document[] $documents
     * @return array<int, float|null>
     */
    protected function scores(array $documents): array
    {
        return array_map(static fn (Document $document): ?float => $document->getScore(), $documents);
    }

    /** @return iterable<string, array{float, float[], float[]}> */
    public static function oddSampleThresholds(): iterable
    {
        // Median 0.5, MAD 0.25.
        $scores = [1.0, 0.25, 0.75, 0.0, 0.5];

        yield 'precision: threshold 0.375' => [0.5, $scores, [1.0, 0.75, 0.5]];
        yield 'threshold lands exactly on a score, which is kept' => [1.0, $scores, [1.0, 0.25, 0.75, 0.5]];
        yield 'recall: threshold 0.125' => [1.5, $scores, [1.0, 0.25, 0.75, 0.5]];
        yield 'negative threshold is clamped to zero, keeping zero scores' => [3.0, $scores, $scores];
        yield 'zero multiplier keeps the upper half' => [0.0, $scores, [1.0, 0.75, 0.5]];
    }

    /**
     * @param float[] $scores
     * @param float[] $expected
     */
    #[DataProvider('oddSampleThresholds')]
    public function test_threshold_follows_median_and_mad_of_an_odd_sample(float $multiplier, array $scores, array $expected): void
    {
        $result = (new AdaptiveThresholdPostProcessor($multiplier))->process(new UserMessage('Question'), $this->scored($scores));

        $this->assertSame($expected, $this->scores($result));
    }

    public function test_a_negative_threshold_is_clamped_so_negative_similarities_are_dropped(): void
    {
        // Median 0.0, MAD 0.25: the raw threshold -0.25 is clamped to 0.
        $result = (new AdaptiveThresholdPostProcessor(1.0))
            ->process(new UserMessage('Question'), $this->scored([0.5, -0.25, 0.0, 0.25, -0.5]));

        $this->assertSame([0.5, 0.0, 0.25], $this->scores($result));
    }

    public function test_even_sample_uses_the_mean_of_the_two_middle_values(): void
    {
        // Median (0.5 + 0.75) / 2 = 0.625, MAD (0.125 + 0.375) / 2 = 0.25, threshold 0.375.
        $result = (new AdaptiveThresholdPostProcessor(1.0))
            ->process(new UserMessage('Question'), $this->scored([0.25, 1.0, 0.5, 0.75]));

        $this->assertSame([1.0, 0.5, 0.75], $this->scores($result));
    }

    public function test_two_documents_are_filtered(): void
    {
        // Median 0.5, MAD 0.25, threshold 0.35 with the default multiplier 0.6.
        $result = (new AdaptiveThresholdPostProcessor())->process(new UserMessage('Question'), $this->scored([0.25, 0.75]));

        $this->assertSame([0.75], $this->scores($result));
    }

    public function test_default_multiplier_is_balanced(): void
    {
        // Median 0.5, MAD 0.25: 0.6 gives threshold 0.35, dropping 0.25 and 0.0.
        $result = (new AdaptiveThresholdPostProcessor())
            ->process(new UserMessage('Question'), $this->scored([1.0, 0.25, 0.75, 0.0, 0.5]));

        $this->assertSame([1.0, 0.75, 0.5], $this->scores($result));
    }

    /** @return iterable<string, array{float[]}> */
    public static function tooSmallSamples(): iterable
    {
        yield 'no documents' => [[]];
        yield 'one document' => [[0.1]];
    }

    /** @param float[] $scores */
    #[DataProvider('tooSmallSamples')]
    public function test_fewer_than_two_documents_are_returned_unchanged(array $scores): void
    {
        $documents = $this->scored($scores);

        $this->assertSame($documents, (new AdaptiveThresholdPostProcessor(0.0))->process(new UserMessage('Question'), $documents));
    }

    /** @return iterable<string, array{float[]}> */
    public static function samplesWithoutSpread(): iterable
    {
        yield 'identical scores' => [[0.5, 0.5, 0.5]];
        yield 'majority identical with an outlier' => [[0.5, 0.5, 0.5, 0.0, 0.9]];
        yield 'spread below the MAD tolerance' => [[0.5, 0.50005, 0.49995, 0.1]];
    }

    /** @param float[] $scores */
    #[DataProvider('samplesWithoutSpread')]
    public function test_samples_without_meaningful_spread_are_not_filtered(array $scores): void
    {
        $documents = $this->scored($scores);

        $this->assertSame($documents, (new AdaptiveThresholdPostProcessor(0.0))->process(new UserMessage('Question'), $documents));
    }

    public function test_result_is_a_list_of_the_same_documents_in_input_order(): void
    {
        $documents = $this->scored([0.0, 1.0, 0.25, 0.75, 0.5]);
        $keyed = [10 => $documents[0], 20 => $documents[1], 30 => $documents[2], 40 => $documents[3], 50 => $documents[4]];

        $result = (new AdaptiveThresholdPostProcessor(0.5))->process(new UserMessage('Question'), $keyed);

        $this->assertSame([$documents[1], $documents[3], $documents[4]], $result);
    }
}
