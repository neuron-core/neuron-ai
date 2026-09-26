<?php

declare(strict_types=1);

namespace NeuronAI\Tests\RAG\Splitter;

use NeuronAI\RAG\Document;
use NeuronAI\RAG\Splitter\DelimiterTextSplitter;
use NeuronAI\RAG\Splitter\SentenceTextSplitter;
use PHPUnit\Framework\TestCase;

use function array_fill;
use function count;
use function explode;
use function implode;
use function mb_strlen;
use function str_repeat;

class MinLengthMergeBoundTest extends TestCase
{
    public function test_min_length_merging_does_not_cascade_every_chunk_into_one(): void
    {
        $maxLength = 10;
        $minLength = 8;
        $text = implode(' ', array_fill(0, 50, 'abcdefg'));

        $chunks = (new DelimiterTextSplitter(maxLength: $maxLength, minLength: $minLength))
            ->splitDocument(new Document($text));

        $this->assertGreaterThan(1, count($chunks));
        foreach ($chunks as $chunk) {
            // A merge may overflow maxLength by at most one short chunk plus the separator.
            $this->assertLessThanOrEqual($maxLength + 1 + $minLength - 1, mb_strlen($chunk->getContent()));
        }
    }

    public function test_min_words_merging_does_not_cascade_every_chunk_into_one(): void
    {
        $maxWords = 6;
        $minWords = 5;

        $chunks = (new SentenceTextSplitter(maxWords: $maxWords, minWords: $minWords))
            ->splitDocument(new Document(str_repeat('Alpha beta gamma delta. ', 20)));

        $this->assertGreaterThan(1, count($chunks));
        foreach ($chunks as $chunk) {
            $this->assertLessThanOrEqual($maxWords + $minWords - 1, count(explode(' ', $chunk->getContent())));
        }
    }
}
