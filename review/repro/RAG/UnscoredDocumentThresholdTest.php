<?php

declare(strict_types=1);

namespace NeuronAI\Tests\RAG\PostProcessor;

use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\PostProcessor\AdaptiveThresholdPostProcessor;
use NeuronAI\RAG\PostProcessor\FixedThresholdPostProcessor;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

class UnscoredDocumentThresholdTest extends TestCase
{
    #[TestWith([-0.5])]
    #[TestWith([0.0])]
    #[TestWith([0.5])]
    public function test_fixed_threshold_drops_an_unscored_document_at_every_threshold(float $threshold): void
    {
        $result = (new FixedThresholdPostProcessor($threshold))->process(new UserMessage('Question'), [new Document('Unscored')]);

        $this->assertSame([], $result);
    }

    public function test_fixed_threshold_keeps_a_zero_scored_document_at_zero_threshold(): void
    {
        $document = (new Document('Zero'))->setScore(0.0);

        $this->assertSame([$document], (new FixedThresholdPostProcessor(0.0))->process(new UserMessage('Question'), [$document]));
    }

    #[TestWith([[0.9, 0.8, 0.85, 0.2]])]
    #[TestWith([[0.02, 0.01, -0.01, -0.02]])]
    /** @param float[] $scores */
    public function test_adaptive_threshold_drops_unscored_documents(array $scores): void
    {
        $scored = [];
        foreach ($scores as $score) {
            $scored[] = (new Document("Doc {$score}"))->setScore($score);
        }
        $unscored = new Document('Unscored');

        $result = (new AdaptiveThresholdPostProcessor())->process(new UserMessage('Question'), [...$scored, $unscored]);

        $this->assertNotContains($unscored, $result);
    }
}
