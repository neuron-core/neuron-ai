<?php

declare(strict_types=1);

namespace NeuronAI\Tests\RAG\Splitter;

use NeuronAI\RAG\Document;
use NeuronAI\RAG\Splitter\SentenceTextSplitter;
use PHPUnit\Framework\TestCase;

use function array_map;

class SentenceBoundaryTest extends TestCase
{
    public function test_a_period_followed_by_a_lowercase_accented_word_does_not_end_the_sentence(): void
    {
        $result = (new SentenceTextSplitter(maxWords: 3))->splitDocument(new Document('See fig. école now.'));

        $this->assertSame(
            ['See fig. école', 'now.'],
            array_map(static fn (Document $chunk): string => $chunk->getContent(), $result)
        );
    }

    public function test_a_period_followed_by_an_uppercase_accented_word_ends_the_sentence(): void
    {
        $result = (new SentenceTextSplitter(maxWords: 3))->splitDocument(new Document('See fig. École now.'));

        $this->assertSame(
            ['See fig.', 'École now.'],
            array_map(static fn (Document $chunk): string => $chunk->getContent(), $result)
        );
    }
}
