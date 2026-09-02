<?php

declare(strict_types=1);

namespace NeuronAI\RAG\VectorStore\Compilers;

use NeuronAI\RAG\VectorStore\ChromaVectorStore;
use NeuronAI\RAG\VectorStore\Filter\Filter;
use NeuronAI\RAG\VectorStore\Filter\FilterOperator;
use function array_map;

/**
 * Compiles to a ChromaDB "where" filter.
 *
 * https://docs.trychroma.com/docs/querying-collections/metadata-filtering
 */
class ChromaFilterCompiler extends MongoStyleFilterCompiler
{
    protected const STORE = ChromaVectorStore::class;

    protected function compileCondition(Filter $condition): array
    {
        if ($condition->operator === FilterOperator::ContainsAny) {
            return ['$or' => array_map(
                static fn (string $value): array => [$condition->field => ['$contains' => $value]],
                $condition->value,
            )];
        }

        if ($condition->operator === FilterOperator::ContainsAll) {
            return ['$and' => array_map(
                static fn (string $value): array => [$condition->field => ['$contains' => $value]],
                $condition->value,
            )];
        }

        return parent::compileCondition($condition);
    }
}
