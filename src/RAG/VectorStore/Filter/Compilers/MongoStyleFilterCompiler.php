<?php

declare(strict_types=1);

namespace NeuronAI\RAG\VectorStore\Filter\Compilers;

use NeuronAI\RAG\VectorStore\Filter\Filter;
use NeuronAI\RAG\VectorStore\Filter\FilterCombinator;
use NeuronAI\RAG\VectorStore\Filter\FilterExpression;
use NeuronAI\RAG\VectorStore\Filter\FilterGroup;
use NeuronAI\RAG\VectorStore\Filter\FilterOperator;
use NeuronAI\RAG\VectorStore\Filter\RawFilter;
use LogicException;

use function count;
use function array_map;

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
    public function compile(FilterExpression $filters): array
    {
        if ($filters instanceof RawFilter) {
            $this->assertTargetsThisStore($filters);
            return (array) $filters->fragment;
        }

        if ($filters instanceof FilterGroup) {
            $conditions = array_map($this->compile(...), $filters->conditions());

            return count($conditions) === 1
                ? $conditions[0]
                : [$filters->operator() === FilterCombinator::And ? '$and' : '$or' => $conditions];
        }

        if ($filters instanceof Filter) {
            return $this->compileCondition($filters);
        }

        throw new LogicException('Unsupported filter expression.');
    }

    /** @return array<string, mixed> */
    protected function compileCondition(Filter $condition): array
    {
        if ($condition->operator === FilterOperator::ContainsAny) {
            return [$condition->field => ['$in' => $condition->value]];
        }

        if ($condition->operator === FilterOperator::ContainsAll) {
            return ['$and' => array_map(
                static fn (string $value): array => [$condition->field => ['$eq' => $value]],
                $condition->value,
            )];
        }

        return [$condition->field => [$this->operator($condition->operator) => $condition->value]];
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
            FilterOperator::ContainsAny, FilterOperator::ContainsAll => throw new LogicException('Array operators compile separately.'),
        };
    }
}
