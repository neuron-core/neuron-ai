<?php

declare(strict_types=1);

namespace NeuronAI\RAG\VectorStore\Filter\Compilers;

use NeuronAI\RAG\VectorStore\Filter\FilterGroup;
use NeuronAI\RAG\VectorStore\Filter\FilterOperator;
use NeuronAI\RAG\VectorStore\Filter\RawFilter;

use function count;

/**
 * Shared dialect base for backends whose filter language is the Mongo-style
 * operator syntax ({field: {$eq: value}}, $and, ...): ChromaDB and Pinecone
 * use it verbatim; MongoDB itself overrides compile() for its field nesting.
 */
abstract class MongoStyleFilterCompiler extends FilterCompiler
{
    /**
     * @return array<string, mixed>
     */
    public function compile(FilterGroup $filters): array
    {
        $conditions = [];

        foreach ($filters->conditions() as $condition) {
            if ($condition instanceof RawFilter) {
                $this->assertTargetsThisStore($condition);
                $conditions[] = (array) $condition->fragment;
                continue;
            }

            $conditions[] = [$condition->field => [$this->operator($condition->operator) => $condition->value]];
        }

        return count($conditions) === 1 ? $conditions[0] : ['$and' => $conditions];
    }

    protected function operator(FilterOperator $operator): string
    {
        return match ($operator) {
            FilterOperator::Eq => '$eq',
            FilterOperator::Neq => '$ne',
            FilterOperator::In => '$in',
            FilterOperator::Gt => '$gt',
            FilterOperator::Gte => '$gte',
            FilterOperator::Lt => '$lt',
            FilterOperator::Lte => '$lte',
        };
    }
}
