<?php

declare(strict_types=1);

namespace NeuronAI\RAG;

use NeuronAI\Exceptions\VectorStoreException;

class VectorSimilarity
{
    public static function cosineSimilarity(array $vector1, array $vector2): float|int
    {
        if (\count($vector1) !== \count($vector2)) {
            throw new VectorStoreException('Vectors must have the same length to apply cosine similarity.');
        }

        $dotProduct = 0.0;
        $magnitude1 = 0.0;
        $magnitude2 = 0.0;

        foreach ($vector1 as $key => $value) {
            if (isset($vector2[$key])) {
                $dotProduct += $value * $vector2[$key];
            }
            $magnitude1 += $value ** 2;
        }

        foreach ($vector2 as $value) {
            $magnitude2 += $value ** 2;
        }

        if ($magnitude1 === 0.0 || $magnitude2 === 0.0) {
            return 0.0;
        }

        return $dotProduct / (\sqrt($magnitude1) * \sqrt($magnitude2));
    }

    /**
     * @throws VectorStoreException
     */
    public static function cosineDistance(array $vector1, array $vector2): float|int
    {
        return 1 - self::cosineSimilarity($vector1, $vector2);
    }

    public static function similarityFromDistance(float|int $distance): float|int
    {
        return 1 - $distance;
    }
}
