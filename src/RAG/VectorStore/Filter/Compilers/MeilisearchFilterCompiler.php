<?php

declare(strict_types=1);

namespace NeuronAI\RAG\VectorStore\Filter\Compilers;

use NeuronAI\RAG\VectorStore\Filter\Filter;
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
    public function compile(FilterGroup $filters): string
    {
        $expressions = [];

        foreach ($filters->conditions() as $condition) {
            if ($condition instanceof RawFilter) {
                $this->assertTargetsThisStore($condition);
                $expressions[] = '(' . (string) $condition->fragment . ')';
                continue;
            }

            $expressions[] = $this->compileCondition($condition);
        }

        return implode(' AND ', $expressions);
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
