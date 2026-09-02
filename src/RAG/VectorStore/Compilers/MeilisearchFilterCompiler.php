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
use NeuronAI\RAG\VectorStore\MeilisearchVectorStore;
use function array_map;
use function implode;
use function is_bool;
use function is_string;
use function str_replace;

class MeilisearchFilterCompiler extends FilterCompiler
{
    protected const STORE = MeilisearchVectorStore::class;

    /**
     * Compile to a Meilisearch filter expression. Filtered attributes must be
     * declared filterable in the index settings.
     *
     * https://www.meilisearch.com/docs/reference/api/search#filter
     */
    public function compile(FilterExpression $filters): string
    {
        return $this->compileExpression($filters, true);
    }

    protected function compileExpression(FilterExpression $filters, bool $root = false): string
    {
        if ($filters instanceof RawFilter) {
            $this->assertTargetsThisStore($filters);
            return '(' . $filters->fragment . ')';
        }

        if ($filters instanceof Filter) {
            return $this->compileCondition($filters);
        }

        if (!$filters instanceof FilterGroup) {
            throw new LogicException('Unsupported filter expression.');
        }

        $separator = $filters->operator() === FilterCombinator::And ? ' AND ' : ' OR ';
        $compiled = implode($separator, array_map(
            fn (FilterExpression $condition): string => $this->compileExpression($condition),
            $filters->conditions(),
        ));

        return $root ? $compiled : '(' . $compiled . ')';
    }

    protected function compileCondition(Filter $condition): string
    {
        $field = $condition->field;

        return match ($condition->operator) {
            FilterOperator::Eq => "{$field} = " . $this->formatValue($condition->value),
            FilterOperator::Neq => "{$field} != " . $this->formatValue($condition->value),
            FilterOperator::In => "{$field} IN [" . implode(', ', array_map($this->formatValue(...), (array) $condition->value)) . ']',
            FilterOperator::Gt => "{$field} > " . $this->formatValue($condition->value),
            FilterOperator::Gte => "{$field} >= " . $this->formatValue($condition->value),
            FilterOperator::Lt => "{$field} < " . $this->formatValue($condition->value),
            FilterOperator::Lte => "{$field} <= " . $this->formatValue($condition->value),
            FilterOperator::ContainsAny => "{$field} IN [" . implode(', ', array_map($this->formatValue(...), $condition->value)) . ']',
            FilterOperator::ContainsAll => '(' . implode(' AND ', array_map(
                fn (string $value): string => "{$field} = " . $this->formatValue($value),
                $condition->value,
            )) . ')',
        };
    }

    protected function formatValue(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_string($value)) {
            return "'" . str_replace(['\\', "'"], ['\\\\', "\\'"], $value) . "'";
        }

        return (string) $value;
    }
}
