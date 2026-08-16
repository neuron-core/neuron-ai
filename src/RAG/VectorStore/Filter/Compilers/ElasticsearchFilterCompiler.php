<?php

declare(strict_types=1);

namespace NeuronAI\RAG\VectorStore\Filter\Compilers;

use NeuronAI\RAG\VectorStore\ElasticsearchVectorStore;
use NeuronAI\RAG\VectorStore\Filter\Filter;
use NeuronAI\RAG\VectorStore\Filter\FilterGroup;
use NeuronAI\RAG\VectorStore\Filter\FilterOperator;
use NeuronAI\RAG\VectorStore\Filter\RawFilter;

use function array_filter;

class ElasticsearchFilterCompiler extends FilterCompiler
{
    protected const STORE = ElasticsearchVectorStore::class;

    /**
     * Compile to an Elasticsearch bool query, usable both as a kNN filter
     * and as a delete-by-query body.
     *
     * @return array<string, mixed>
     */
    public function compile(FilterGroup $filters): array
    {
        $must = [];
        $mustNot = [];

        foreach ($filters->conditions() as $condition) {
            if ($condition instanceof RawFilter) {
                $this->assertTargetsThisStore($condition);
                $must[] = (array) $condition->fragment;
                continue;
            }

            if ($condition->operator === FilterOperator::Neq) {
                $must[] = ['exists' => ['field' => $condition->field]];
                $mustNot[] = ['term' => [$condition->field => $condition->value]];
                continue;
            }

            $must[] = $this->compileCondition($condition);
        }

        return ['bool' => array_filter(['must' => $must, 'must_not' => $mustNot])];
    }

    /**
     * @return array<string, mixed>
     */
    protected function compileCondition(Filter $condition): array
    {
        return match ($condition->operator) {
            FilterOperator::Eq, FilterOperator::Neq => ['term' => [$condition->field => $condition->value]],
            FilterOperator::In => ['terms' => [$condition->field => $condition->value]],
            FilterOperator::Gt => ['range' => [$condition->field => ['gt' => $condition->value]]],
            FilterOperator::Gte => ['range' => [$condition->field => ['gte' => $condition->value]]],
            FilterOperator::Lt => ['range' => [$condition->field => ['lt' => $condition->value]]],
            FilterOperator::Lte => ['range' => [$condition->field => ['lte' => $condition->value]]],
        };
    }
}
