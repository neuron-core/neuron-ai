<?php

declare(strict_types=1);

namespace NeuronAI\RAG\Splitter;

use InvalidArgumentException;
use NeuronAI\RAG\Document;

use function array_filter;
use function array_map;
use function array_merge;
use function array_slice;
use function count;
use function implode;
use function preg_split;
use function trim;

/**
 * Splits text into sentences, groups into word-based chunks, and applies
 * overlap in terms of words.
 */
class SentenceTextSplitter extends AbstractSplitter
{
    private readonly int $maxWords;
    private readonly int $overlapWords;
    private readonly int $minWords;

    /**
     * @param int $maxWords    Maximum number of words per chunk
     * @param int $overlapWords Number of overlapping words between chunks
     * @param int $minWords    Minimum number of words per chunk (small chunks are merged into the previous one)
     */
    public function __construct(int $maxWords = 200, int $overlapWords = 0, int $minWords = 0)
    {
        if ($maxWords <= 0) {
            throw new InvalidArgumentException('maxWords must be greater than 0');
        }

        if ($overlapWords < 0) {
            throw new InvalidArgumentException('overlapWords must be greater than or equal to 0');
        }

        if ($overlapWords >= $maxWords) {
            throw new InvalidArgumentException('Overlap must be less than maxWords');
        }

        if ($minWords < 0) {
            throw new InvalidArgumentException('minWords must be greater than or equal to 0');
        }

        if ($minWords > 0 && $minWords >= $maxWords) {
            throw new InvalidArgumentException('minWords must be less than maxWords');
        }

        $this->maxWords = $maxWords;
        $this->overlapWords = $overlapWords;
        $this->minWords = $minWords;
    }

    /**
     * Splits text into word-based chunks, preserving sentence boundaries.
     *
     * @return Document[] Array of Document chunks
     */
    public function splitDocument(Document $document): array
    {
        $paragraphs = preg_split('/\n{2,}/', $document->getContent());
        $chunks = [];
        $currentWords = [];

        foreach ($paragraphs as $paragraph) {
            $sentences = $this->splitSentences($paragraph);

            foreach ($sentences as $sentence) {
                $sentenceWords = $this->tokenizeWords($sentence);

                if ($sentenceWords === []) {
                    continue;
                }

                // If the sentence alone exceeds the limit, split it
                if (count($sentenceWords) > $this->maxWords) {
                    if ($currentWords !== []) {
                        $chunks[] = $currentWords;
                        $currentWords = [];
                    }
                    $chunks = array_merge($chunks, $this->splitLongSentence($sentenceWords));
                    continue;
                }

                $candidateCount = count($currentWords) + count($sentenceWords);

                if ($candidateCount > $this->maxWords) {
                    if ($currentWords !== []) {
                        $chunks[] = $currentWords;
                    }
                    $currentWords = $sentenceWords;
                } else {
                    $currentWords = array_merge($currentWords, $sentenceWords);
                }
            }
        }

        if ($currentWords !== []) {
            $chunks[] = $currentWords;
        }

        if ($this->overlapWords > 0) {
            $chunks = $this->applyOverlap($chunks);
        }

        $chunks = $this->enforceMinWords($chunks);

        $split = [];
        foreach ($chunks as $wordArray) {
            $newDocument = new Document(implode(' ', $wordArray));
            $newDocument->sourceType = $document->getSourceType();
            $newDocument->sourceName = $document->getSourceName();
            $newDocument->metadata = $document->metadata;
            $split[] = $newDocument;
        }

        return $split;
    }

    /**
     * Robust regex for sentence splitting (handles ., !, ?, …, periods followed by quotes, etc)
     *
     * @return string[]
     */
    private function splitSentences(string $text): array
    {
        $pattern = '/(?<=[.!?…])\s+(?=(?:[\"\'\""\'\'«»„""]?)[A-ZÀ-Ÿ])/u';
        $sentences = preg_split($pattern, trim($text));
        return array_filter(array_map(trim(...), $sentences));
    }

    /**
     * Tokenizes text into words (simple whitespace split).
     *
     * @return string[] Array of words
     */
    private function tokenizeWords(string $text): array
    {
        $words = preg_split('/\s+/u', trim($text));
        return $words === false ? [] : array_filter($words, static fn (string $w): bool => $w !== '');
    }

    /**
     * Merges chunks that fall below minWords into the previous chunk.
     *
     * @param  array<array<string>>  $chunks
     * @return array<array<string>>
     */
    private function enforceMinWords(array $chunks): array
    {
        if ($this->minWords <= 0 || count($chunks) <= 1) {
            return $chunks;
        }

        $result = [$chunks[0]];

        for ($i = 1, $count = count($chunks); $i < $count; $i++) {
            $wordCount = count($chunks[$i]);

            if ($wordCount < $this->minWords) {
                $lastIndex = count($result) - 1;
                $result[$lastIndex] = array_merge($result[$lastIndex], $chunks[$i]);
            } else {
                $result[] = $chunks[$i];
            }
        }

        return $result;
    }

    /**
     * Applies overlap of words between consecutive chunks.
     *
     * @param  array<array<string>>  $chunks
     * @return array<array<string>>
     */
    private function applyOverlap(array $chunks): array
    {
        if ($chunks === []) {
            return [];
        }

        $result = [$chunks[0]];
        $count = count($chunks);

        for ($i = 1; $i < $count; $i++) {
            $prevWords = $chunks[$i - 1];
            $curWords = $chunks[$i];

            $overlap = array_slice($prevWords, -$this->overlapWords);

            // Only remove leading words if current chunk has enough words
            $remaining = count($curWords) > $this->overlapWords
                ? array_slice($curWords, $this->overlapWords)
                : $curWords;

            $result[] = array_merge($overlap, $remaining);
        }

        return $result;
    }

    /**
     * Splits a long sentence into smaller chunks that respect the maxWords limit.
     *
     * @param  string[]  $words Array of words from the sentence
     * @return array<array<string>>
     */
    private function splitLongSentence(array $words): array
    {
        $chunks = [];
        $currentChunk = [];

        foreach ($words as $word) {
            if (count($currentChunk) >= $this->maxWords) {
                $chunks[] = $currentChunk;
                $currentChunk = [];
            }
            $currentChunk[] = $word;
        }

        if ($currentChunk !== []) {
            $chunks[] = $currentChunk;
        }

        return $chunks;
    }
}
