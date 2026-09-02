<?php

declare(strict_types=1);

namespace NeuronAI\RAG\VectorStore\Compilers;

use LogicException;
use NeuronAI\RAG\VectorStore\Filter\Filter;
use NeuronAI\RAG\VectorStore\Filter\FilterCombinator;
use NeuronAI\RAG\VectorStore\Filter\FilterExpression;
use NeuronAI\RAG\VectorStore\Filter\FilterGroup;
use NeuronAI\RAG\VectorStore\Filter\FilterOperator;
use NeuronAI\RAG\VectorStore\Filter\RawFilter;
use NeuronAI\RAG\VectorStore\QdrantVectorStore;

use function array_map;
use function count;

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
    public function compile(FilterExpression $filters): array
    {
        if ($filters instanceof RawFilter) {
            $this->assertTargetsThisStore($filters);
            return [(array) $filters->fragment];
        }

        if ($filters instanceof Filter) {
            return [$this->compileCondition($filters)];
        }

        if (!$filters instanceof FilterGroup) {
            throw new LogicException('Unsupported filter expression.');
        }

        if ($filters->operator() === FilterCombinator::Or) {
            return [['should' => array_map(
                $this->condition(...),
                $filters->conditions(),
            )]];
        }

        $conditions = [];
        foreach ($filters->conditions() as $condition) {
            $conditions = [...$conditions, ...$this->compile($condition)];
        }

        return $conditions;
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
            FilterOperator::ContainsAny => ['key' => $condition->field, 'match' => ['any' => $condition->value]],
            FilterOperator::ContainsAll => ['must' => array_map(
                static fn (string $value): array => [
                    'key' => $condition->field,
                    'match' => ['value' => $value],
                ],
                $condition->value,
            )],
        };
    }

    /** @return array<string, mixed> */
    protected function condition(FilterExpression $expression): array
    {
        $compiled = $this->compile($expression);

        return count($compiled) === 1 ? $compiled[0] : ['must' => $compiled];
    }
}
