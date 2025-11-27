<?php

declare(strict_types=1);

namespace NeuronAI\RAG\Splitter;

use NeuronAI\RAG\Document;
use InvalidArgumentException;

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

    /**
     * @param int $maxWords    Maximum number of words per chunk
     * @param int $overlapWords Number of overlapping words between chunks
     */
    public function __construct(int $maxWords = 200, int $overlapWords = 0)
    {
        if ($overlapWords >= $maxWords) {
            throw new InvalidArgumentException('Overlap must be less than maxWords');
        }

        $this->maxWords = $maxWords;
        $this->overlapWords = $overlapWords;
    }

    /**
     * Splits text into word-based chunks, preserving sentence boundaries.
     *
     * @return Document[] Array of Document chunks
     */
    public function splitDocument(Document $document): array
    {
        // Split by paragraphs (2 or more newlines)
        $paragraphs = preg_split('/\n{2,}/', $document->getContent());
        $chunks = [];
        $currentWords = [];

        foreach ($paragraphs as $paragraph) {
            $sentences = $this->splitSentences($paragraph);

            foreach ($sentences as $sentence) {
                $sentenceWords = $this->tokenizeWords($sentence);

                // If the sentence alone exceeds the limit, split it
                if (count($sentenceWords) > $this->maxWords) {
                    if ($currentWords !== []) {
                        $chunks[] = implode(' ', $currentWords);
                        $currentWords = [];
                    }
                    $chunks = array_merge($chunks, $this->splitLongSentence($sentenceWords));
                    continue;
                }

                $candidateCount = count($currentWords) + count($sentenceWords);

                if ($candidateCount > $this->maxWords) {
                    if ($currentWords !== []) {
                        $chunks[] = implode(' ', $currentWords);
                    }
                    $currentWords = $sentenceWords;
                } else {
                    $currentWords = array_merge($currentWords, $sentenceWords);
                }
            }
        }

        if ($currentWords !== []) {
            $chunks[] = implode(' ', $currentWords);
        }

        // Apply overlap only if necessary
        if ($this->overlapWords > 0) {
            $chunks = $this->applyOverlap($chunks);
        }

        $split = [];
        foreach ($chunks as $chunk) {
            $newDocument = new Document($chunk);
            $newDocument->sourceType = $document->getSourceType();
            $newDocument->sourceName = $document->getSourceName();
            $split[] = $newDocument;
        }

        return $split;
    }

    /**
     * Robust regex for sentence splitting (handles ., !, ?, …, periods followed by quotes, etc)
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
        return preg_split('/\s+/u', trim($text));
    }

    /**
     * Applies overlap of words between consecutive chunks.
     *
     * @param string[] $chunks
     * @return string[] Array of chunks with overlap applied
     */
    private function applyOverlap(array $chunks): array
    {
        if ($chunks === []) {
            return [];
        }

        $result = [$chunks[0]]; // First chunk remains unchanged
        $count = count($chunks);

        for ($i = 1; $i < $count; $i++) {
            $prevWords = $this->tokenizeWords($chunks[$i - 1]);
            $curWords = $this->tokenizeWords($chunks[$i]);

            // Get only the words needed for overlap
            $overlap = array_slice($prevWords, -$this->overlapWords);

            // Remove duplicate words at the beginning of current chunk
            $curWords = array_slice($curWords, $this->overlapWords);

            $merged = array_merge($overlap, $curWords);
            $result[] = implode(' ', $merged);
        }

        return $result;
    }

    /**
     * Splits a long sentence into smaller chunks that respect the maxWords limit.
     *
     * @param string[] $words Array of words from the sentence
     * @return string[] Array of chunks
     */
    private function splitLongSentence(array $words): array
    {
        $chunks = [];
        $currentChunk = [];

        foreach ($words as $word) {
            if (count($currentChunk) >= $this->maxWords) {
                $chunks[] = implode(' ', $currentChunk);
                $currentChunk = [];
            }
            $currentChunk[] = $word;
        }

        if ($currentChunk !== []) {
            $chunks[] = implode(' ', $currentChunk);
        }

        return $chunks;
    }
}
