<?php

declare(strict_types=1);

namespace NeuronAI\Tests\RAG\PostProcessor;

use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\PostProcessor\FixedThresholdPostProcessor;
use PHPUnit\Framework\TestCase;

class FixedThresholdPostProcessorTest extends TestCase
{
    public function test_process_filters_documents_below_threshold(): void
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

        $processor = new FixedThresholdPostProcessor(0.8);
        $question = new UserMessage('test question');

        $result = $processor->process($question, $documents);

        $this->assertCount(2, $result);
        $this->assertEquals(0.8, $result[0]->getScore());
        $this->assertEquals(0.9, $result[1]->getScore());
    }
}
