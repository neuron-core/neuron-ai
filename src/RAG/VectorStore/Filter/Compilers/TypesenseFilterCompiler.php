<?php

declare(strict_types=1);

namespace NeuronAI\RAG\VectorStore\Filter\Compilers;

use NeuronAI\RAG\VectorStore\Filter\Filter;
use NeuronAI\RAG\VectorStore\Filter\FilterGroup;
use NeuronAI\RAG\VectorStore\Filter\FilterOperator;
use NeuronAI\RAG\VectorStore\Filter\RawFilter;
use NeuronAI\RAG\VectorStore\TypesenseVectorStore;

use function array_map;
use function implode;
use function is_bool;
use function is_string;
use function str_replace;

class TypesenseFilterCompiler extends FilterCompiler
{
    protected const STORE = TypesenseVectorStore::class;

    /**
     * Compile to a Typesense "filter_by" expression.
     *
     * https://typesense.org/docs/latest/api/search.html#filter-parameters
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

        return implode(' && ', $expressions);
    }

    protected function compileCondition(Filter $condition): string
    {
        $field = $condition->field;

        return match ($condition->operator) {
            FilterOperator::Eq => "{$field}:=" . $this->formatValue($condition->value),
            FilterOperator::Neq => "{$field}:!=" . $this->formatValue($condition->value),
            FilterOperator::In => "{$field}:=[" . implode(', ', array_map($this->formatValue(...), (array) $condition->value)) . ']',
            FilterOperator::Gt => "{$field}:>" . $this->formatValue($condition->value),
            FilterOperator::Gte => "{$field}:>=" . $this->formatValue($condition->value),
            FilterOperator::Lt => "{$field}:<" . $this->formatValue($condition->value),
            FilterOperator::Lte => "{$field}:<=" . $this->formatValue($condition->value),
        };
    }

    protected function formatValue(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_string($value)) {
            // Backticks delimit values with special characters; Typesense has
            // no escape for a literal backtick, so it is stripped.
            return '`' . str_replace('`', '', $value) . '`';
        }

        return (string) $value;
    }
}
