<?php

declare(strict_types=1);

namespace NeuronAI\RAG;

use NeuronAI\Exceptions\VectorStoreException;

use function count;
use function sqrt;

class VectorSimilarity
{
    public static function cosineSimilarity(array $vector1, array $vector2): float
    {
        $count = count($vector1);
        if ($count !== count($vector2)) {
            throw new VectorStoreException('Vectors must have the same length to apply cosine similarity.');
        }

        $dotProduct = 0.0;
        $magnitude1 = 0.0;
        $magnitude2 = 0.0;

        for ($i = 0; $i < $count; $i++) {
            $dotProduct += $vector1[$i] * $vector2[$i];
            $magnitude1 += $vector1[$i] * $vector1[$i];
            $magnitude2 += $vector2[$i] * $vector2[$i];
        }

        if ($magnitude1 === 0.0 || $magnitude2 === 0.0) {
            return 0.0;
        }

        return $dotProduct / sqrt($magnitude1 * $magnitude2);
    }

    /**
     * @throws VectorStoreException
     */
    public static function cosineDistance(array $vector1, array $vector2): float
    {
        return 1 - self::cosineSimilarity($vector1, $vector2);
    }

    public static function similarityFromDistance(float $distance): float
    {
        return 1 - $distance;
    }
}
