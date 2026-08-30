<?php

declare(strict_types=1);

namespace NeuronAI\RAG\VectorStore\Filter\Compilers;

use NeuronAI\RAG\VectorStore\ElasticsearchVectorStore;
use NeuronAI\RAG\VectorStore\Filter\Filter;
use NeuronAI\RAG\VectorStore\Filter\FilterCombinator;
use NeuronAI\RAG\VectorStore\Filter\FilterExpression;
use NeuronAI\RAG\VectorStore\Filter\FilterGroup;
use NeuronAI\RAG\VectorStore\Filter\FilterOperator;
use NeuronAI\RAG\VectorStore\Filter\RawFilter;
use LogicException;

use function array_filter;
use function array_map;

class ElasticsearchFilterCompiler extends FilterCompiler
{
    protected const STORE = ElasticsearchVectorStore::class;

    /**
     * Compile to an Elasticsearch bool query, usable both as a kNN filter
     * and as a delete-by-query body.
     *
     * @return array<string, mixed>
     */
    public function compile(FilterExpression $filters): array
    {
        if ($filters instanceof RawFilter) {
            $this->assertTargetsThisStore($filters);
            return (array) $filters->fragment;
        }

        if ($filters instanceof Filter) {
            if ($filters->operator === FilterOperator::Neq) {
                return ['bool' => [
                    'must' => [['exists' => ['field' => $filters->field]]],
                    'must_not' => [['term' => [$filters->field => $filters->value]]],
                ]];
            }

            return $this->compileCondition($filters);
        }

        if (!$filters instanceof FilterGroup) {
            throw new LogicException('Unsupported filter expression.');
        }

        if ($filters->operator() === FilterCombinator::Or) {
            return ['bool' => [
                'should' => array_map($this->compile(...), $filters->conditions()),
                'minimum_should_match' => 1,
            ]];
        }

        $must = [];
        $mustNot = [];

        foreach ($filters->conditions() as $condition) {
            if ($condition instanceof Filter && $condition->operator === FilterOperator::Neq) {
                $must[] = ['exists' => ['field' => $condition->field]];
                $mustNot[] = ['term' => [$condition->field => $condition->value]];
                continue;
            }

            $must[] = $this->compile($condition);
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
            FilterOperator::ContainsAny => ['terms' => [$condition->field => $condition->value]],
            FilterOperator::ContainsAll => ['bool' => ['must' => array_map(
                static fn (string $value): array => ['term' => [$condition->field => $value]],
                $condition->value,
            )]],
        };
    }
}
