<?php

declare(strict_types=1);

namespace NeuronAI\Tests\RAG\PostProcessor;

use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\PostProcessor\FixedThresholdPostProcessor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function array_map;

class FixedThresholdPostProcessorTest extends TestCase
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
    public static function thresholds(): iterable
    {
        yield 'keeps scores at or above the threshold in their order' => [0.8, [0.8, 0.3, 0.7, 0.4, 0.9], [0.8, 0.9]];
        yield 'the threshold itself is inclusive' => [0.5, [0.5, 0.49999, 0.50001], [0.5, 0.50001]];
        yield 'zero keeps zero scores' => [0.0, [0.0, 0.1], [0.0, 0.1]];
        yield 'negative threshold keeps negative similarities above it' => [-0.5, [-0.4, -0.6, 0.2], [-0.4, 0.2]];
        yield 'nothing reaches a threshold of one' => [1.0, [0.99, 0.5], []];
    }

    /**
     * @param float[] $scores
     * @param float[] $expected
     */
    #[DataProvider('thresholds')]
    public function test_documents_below_the_threshold_are_dropped(float $threshold, array $scores, array $expected): void
    {
        $result = (new FixedThresholdPostProcessor($threshold))->process(new UserMessage('Question'), $this->scored($scores));

        $this->assertSame($expected, $this->scores($result));
    }

    public function test_default_threshold_is_one_half(): void
    {
        $result = (new FixedThresholdPostProcessor())->process(new UserMessage('Question'), $this->scored([0.5, 0.49]));

        $this->assertSame([0.5], $this->scores($result));
    }

    public function test_result_is_a_list_of_the_same_documents(): void
    {
        $documents = [3 => (new Document('Low'))->setScore(0.1), 7 => (new Document('High'))->setScore(0.9)];

        $result = (new FixedThresholdPostProcessor(0.5))->process(new UserMessage('Question'), $documents);

        $this->assertSame([$documents[7]], $result);
    }

    public function test_no_documents_yield_no_documents(): void
    {
        $this->assertSame([], (new FixedThresholdPostProcessor())->process(new UserMessage('Question'), []));
    }

    public function test_unscored_documents_are_dropped_by_a_positive_threshold(): void
    {
        $unscored = new Document('Never scored');

        $this->assertSame([], (new FixedThresholdPostProcessor(0.1))->process(new UserMessage('Question'), [$unscored]));
    }
}
