<?php

declare(strict_types=1);

namespace NeuronAI\RAG\PostProcessor;

use NeuronAI\Chat\Messages\Message;
use NeuronAI\RAG\Document;

use function abs;
use function array_filter;
use function array_map;
use function array_values;
use function count;
use function floor;
use function max;
use function sort;

class AdaptiveThresholdPostProcessor implements PostProcessorInterface
{
    /**
     * Creates a post-processor that filters documents using an adaptive threshold
     * based on median and MAD (Median Absolute Deviation).
     *
     * Recommended multiplier values:
     * - 0.2-0.4: High precision mode. For more targeted results with fewer but more relevant documents.
     * - 0.5-0.7: Balanced mode. Recommended setting for general use cases.
     * - 0.8-1.0: High recall mode. For more inclusive results that prioritize coverage.
     * - >1.0: Not recommended as it tends to include almost all documents.
     *
     * @param float $multiplier multiplier for MAD (higher values = more inclusive)
     */
    public function __construct(private readonly float $multiplier = 0.6)
    {
    }

    /**
     * Filters documents using a threshold calculated dynamically with median and MAD
     * for greater robustness against outliers.
     */
    public function process(Message $question, array $documents): array
    {
        if (count($documents) < 2) {
            return $documents;
        }

        $scores = array_map(fn (Document $document): float => $document->getScore(), $documents);
        $median = $this->calculateMedian($scores);
        $mad = $this->calculateMAD($scores, $median);

        // If MAD is zero (many equal values), don't filter
        if ($mad <= 0.0001) {
            return $documents;
        }

        // Threshold: median - multiplier * MAD
        $threshold = $median - ($this->multiplier * $mad);

        // Ensure a threshold is not negative
        $threshold = max(0, $threshold);

        return array_values(array_filter($documents, fn (Document $document): bool => $document->getScore() >= $threshold));
    }

    /**
     * Calculates the median of an array of values
     *
     * @param float[] $values
     */
    protected function calculateMedian(array $values): float
    {
        sort($values);
        $n = count($values);
        $mid = (int) floor(($n - 1) / 2);

        if ($n % 2 !== 0) {
            return $values[$mid];
        }

        return ($values[$mid] + $values[$mid + 1]) / 2.0;
    }

    /**
     * Calculates the Median Absolute Deviation (MAD)
     *
     * @param float[] $values
     * @param float $median The median of the values
     */
    protected function calculateMAD(array $values, float $median): float
    {
        $deviations = array_map(fn (float$v): float => abs($v - $median), $values);

        // MAD is the median of deviations
        return $this->calculateMedian($deviations);
    }
}
