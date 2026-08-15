<?php

declare(strict_types=1);

namespace NeuronAI\RAG\VectorStore\Filter\Compilers;

use NeuronAI\RAG\VectorStore\Filter\Filter;
use NeuronAI\RAG\VectorStore\Filter\FilterGroup;
use NeuronAI\RAG\VectorStore\Filter\FilterOperator;
use NeuronAI\RAG\VectorStore\Filter\RawFilter;
use NeuronAI\RAG\VectorStore\QdrantVectorStore;

class QdrantFilterCompiler extends FilterCompiler
{
    protected const STORE = QdrantVectorStore::class;

    /**
     * Compile to Qdrant "must" conditions.
     *
     * https://qdrant.tech/documentation/concepts/filtering/
     *
     * @return array<int, array<string, mixed>>
     */
    public function compile(FilterGroup $filters): array
    {
        $must = [];

        foreach ($filters->conditions() as $condition) {
            if ($condition instanceof RawFilter) {
                $this->assertTargetsThisStore($condition);
                $must[] = (array) $condition->fragment;
                continue;
            }

            $must[] = $this->compileCondition($condition);
        }

        return $must;
    }

    /**
     * @return array<string, mixed>
     */
    protected function compileCondition(Filter $condition): array
    {
        return match ($condition->operator) {
            FilterOperator::Eq => ['key' => $condition->field, 'match' => ['value' => $condition->value]],
            FilterOperator::Neq => ['key' => $condition->field, 'match' => ['except' => [$condition->value]]],
            FilterOperator::In => ['key' => $condition->field, 'match' => ['any' => $condition->value]],
            FilterOperator::Gt => ['key' => $condition->field, 'range' => ['gt' => $condition->value]],
            FilterOperator::Gte => ['key' => $condition->field, 'range' => ['gte' => $condition->value]],
            FilterOperator::Lt => ['key' => $condition->field, 'range' => ['lt' => $condition->value]],
            FilterOperator::Lte => ['key' => $condition->field, 'range' => ['lte' => $condition->value]],
        };
    }
}
