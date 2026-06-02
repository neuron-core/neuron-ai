<?php

declare(strict_types=1);

namespace NeuronAI\RAG\Splitter;

use InvalidArgumentException;
use NeuronAI\RAG\Document;

use function array_map;
use function array_slice;
use function count;
use function explode;
use function implode;
use function mb_strlen;
use function array_sum;
use function min;

class DelimiterTextSplitter extends AbstractSplitter
{
    private readonly int $separatorLength;

    public function __construct(
        private readonly int $maxLength = 1000,
        private readonly string $separator = ' ',
        private readonly int $wordOverlap = 0,
        private readonly int $minLength = 0,
    ) {
        if ($maxLength <= 0) {
            throw new InvalidArgumentException('maxLength must be greater than 0');
        }

        if ($separator === '') {
            throw new InvalidArgumentException('separator must not be empty');
        }

        if ($wordOverlap < 0) {
            throw new InvalidArgumentException('wordOverlap must be greater than or equal to 0');
        }

        if ($minLength < 0) {
            throw new InvalidArgumentException('minLength must be greater than or equal to 0');
        }

        if ($minLength > 0 && $minLength >= $maxLength) {
            throw new InvalidArgumentException('minLength must be less than maxLength');
        }

        $this->separatorLength = mb_strlen($separator);
    }

    /**
     * @return Document[]
     */
    public function splitDocument(Document $document): array
    {
        $text = $document->getContent();

        if ($text === '') {
            return [];
        }

        if (mb_strlen($text) <= $this->maxLength) {
            return [$document];
        }

        $parts = explode($this->separator, $text);

        $chunks = $this->createChunksWithOverlap($parts);
        $chunks = $this->enforceMinLength($chunks);

        $split = [];
        foreach ($chunks as $chunk) {
            $newDocument = new Document($chunk);
            $newDocument->sourceType = $document->getSourceType();
            $newDocument->sourceName = $document->getSourceName();
            $newDocument->metadata = $document->metadata;
            $split[] = $newDocument;
        }

        return $split;
    }

    /**
     * @param  array<string>  $words
     * @return array<string>
     */
    private function createChunksWithOverlap(array $words): array
    {
        $chunks = [];
        $currentChunk = [];
        $currentChunkLength = 0;

        foreach ($words as $word) {
            if ($word === '') {
                continue;
            }

            $wordLength = mb_strlen($word);
            $addedLength = ($currentChunk === [] ? 0 : $this->separatorLength) + $wordLength;

            if ($currentChunkLength + $addedLength <= $this->maxLength || $currentChunk === []) {
                $currentChunk[] = $word;
                $currentChunkLength += $addedLength;
            } else {
                $chunks[] = implode($this->separator, $currentChunk);

                $calculatedOverlap = min($this->wordOverlap, count($currentChunk) - 1);
                $overlapWords = $calculatedOverlap > 0 ? array_slice($currentChunk, -$calculatedOverlap) : [];

                $currentChunk = [...$overlapWords, $word];
                $currentChunkLength = $this->calculateChunkLength($currentChunk);
            }
        }

        if ($currentChunk !== []) {
            $chunks[] = implode($this->separator, $currentChunk);
        }

        return $chunks;
    }

    /**
     * Merges chunks that fall below minLength into the previous chunk.
     *
     * @param  array<string>  $chunks
     * @return array<string>
     */
    private function enforceMinLength(array $chunks): array
    {
        if ($this->minLength <= 0 || count($chunks) <= 1) {
            return $chunks;
        }

        $result = [$chunks[0]];

        for ($i = 1, $count = count($chunks); $i < $count; $i++) {
            if (mb_strlen($chunks[$i]) < $this->minLength) {
                $lastIndex = count($result) - 1;
                $result[$lastIndex] = implode($this->separator, [$result[$lastIndex], $chunks[$i]]);
            } else {
                $result[] = $chunks[$i];
            }
        }

        return $result;
    }

    /**
     * @param  array<string>  $chunk
     */
    private function calculateChunkLength(array $chunk): int
    {
        if ($chunk === []) {
            return 0;
        }

        return array_sum(array_map(mb_strlen(...), $chunk))
            + (count($chunk) - 1) * $this->separatorLength;
    }
}
