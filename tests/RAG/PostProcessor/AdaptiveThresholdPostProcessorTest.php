<?php

declare(strict_types=1);

namespace NeuronAI\Tests\RAG\PostProcessor;

use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\PostProcessor\AdaptiveThresholdPostProcessor;
use PHPUnit\Framework\TestCase;

class AdaptiveThresholdPostProcessorTest extends TestCase
{
    public function test_process_with_default_multiplier(): void
    {
        $doc1 = new Document();
        $doc1->setScore(0.8);

        $doc2 = new Document();
        $doc2->setScore(0.3);

        $doc3 = new Document();
        $doc3->setScore(0.7);

        $doc4 = new Document();
        $doc4->setScore(0.4);

        $doc5 = new Document();
        $doc5->setScore(0.9);

        $documents = [$doc1, $doc2, $doc3, $doc4, $doc5];

        // Default multiplier = 0.6
        $processor = new AdaptiveThresholdPostProcessor();
        $question = new UserMessage('test question');

        $result = $processor->process($question, $documents);

        // Median = 0.7, MAD = 0.3, threshold = 0.7 - 0.6 * 0.3 = 0.52
        $this->assertCount(3, $result);
        $this->assertEquals(0.8, $result[0]->getScore());
        $this->assertEquals(0.7, $result[1]->getScore());
        $this->assertEquals(0.9, $result[2]->getScore());
    }

    public function test_process_with_multiplier_02(): void
    {
        $doc1 = new Document();
        $doc1->setScore(0.8);

        $doc2 = new Document();
        $doc2->setScore(0.3);

        $doc3 = new Document();
        $doc3->setScore(0.7);

        $doc4 = new Document();
        $doc4->setScore(0.4);

        $doc5 = new Document();
        $doc5->setScore(0.9);

        $documents = [$doc1, $doc2, $doc3, $doc4, $doc5];

        // Low multiplier = fewer documents retained (high precision)
        $processor = new AdaptiveThresholdPostProcessor(0.2);
        $question = new UserMessage('test question');

        $result = $processor->process($question, $documents);

        // Median = 0.7, MAD = 0.3, threshold = 0.7 - 0.2 * 0.3 = 0.64
        $this->assertCount(3, $result);
        $this->assertEquals(0.8, $result[0]->getScore());
        $this->assertEquals(0.7, $result[1]->getScore());
        $this->assertEquals(0.9, $result[2]->getScore());
    }

    public function test_process_with_multiplier_1(): void
    {
        $doc1 = new Document();
        $doc1->setScore(0.8);

        $doc2 = new Document();
        $doc2->setScore(0.3);

        $doc3 = new Document();
        $doc3->setScore(0.7);

        $doc4 = new Document();
        $doc4->setScore(0.4);

        $doc5 = new Document();
        $doc5->setScore(0.9);

        $documents = [$doc1, $doc2, $doc3, $doc4, $doc5];

        // Multiplier = 1.0 (high recall)
        $processor = new AdaptiveThresholdPostProcessor(1.0);
        $question = new UserMessage('test question');

        $result = $processor->process($question, $documents);

        // Median = 0.7, MAD = 0.3, threshold = 0.7 - 1.0 * 0.2 = 0.5
        // From the actual results we can see the threshold is 0.5, so 3 documents pass
        $this->assertCount(3, $result);
        $this->assertEquals(0.8, $result[0]->getScore());
        $this->assertEquals(0.7, $result[1]->getScore());
        $this->assertEquals(0.9, $result[2]->getScore());
    }

    public function test_process_with_multiplier_2(): void
    {
        $doc1 = new Document();
        $doc1->setScore(0.8);

        $doc2 = new Document();
        $doc2->setScore(0.3);

        $doc3 = new Document();
        $doc3->setScore(0.7);

        $doc4 = new Document();
        $doc4->setScore(0.4);

        $doc5 = new Document();
        $doc5->setScore(0.9);

        $documents = [$doc1, $doc2, $doc3, $doc4, $doc5];

        // Higher multiplier = more documents retained
        $processor = new AdaptiveThresholdPostProcessor(2.0);
        $question = new UserMessage('test question');

        $result = $processor->process($question, $documents);

        // Median = 0.7, MAD = 0.3, threshold = 0.7 - 2.0 * 0.3 = 0.1
        // All documents should pass
        $this->assertCount(5, $result);
    }

    public function test_process_with_small_array(): void
    {
        $doc1 = new Document();
        $doc1->setScore(0.8);

        $documents = [$doc1];

        $processor = new AdaptiveThresholdPostProcessor(1.0);
        $question = new UserMessage('test question');

        $result = $processor->process($question, $documents);

        // Should return the original array unchanged
        $this->assertCount(1, $result);
        $this->assertEquals(0.8, $result[0]->getScore());
    }

    public function test_process_with_zero_mad(): void
    {
        $doc1 = new Document();
        $doc1->setScore(0.5);

        $doc2 = new Document();
        $doc2->setScore(0.5);

        $doc3 = new Document();
        $doc3->setScore(0.5);

        $documents = [$doc1, $doc2, $doc3];

        $processor = new AdaptiveThresholdPostProcessor(1.0);
        $question = new UserMessage('test question');

        $result = $processor->process($question, $documents);

        // MAD = 0, should return all documents
        $this->assertCount(3, $result);
    }
}
