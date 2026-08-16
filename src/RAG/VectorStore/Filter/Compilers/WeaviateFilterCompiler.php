<?php

declare(strict_types=1);

namespace NeuronAI\RAG\VectorStore\Filter\Compilers;

use NeuronAI\RAG\VectorStore\Filter\Filter;
use NeuronAI\RAG\VectorStore\Filter\FilterGroup;
use NeuronAI\RAG\VectorStore\Filter\FilterOperator;
use NeuronAI\RAG\VectorStore\Filter\RawFilter;
use NeuronAI\RAG\VectorStore\WeaviateVectorStore;

use function array_is_list;
use function array_map;
use function count;
use function implode;
use function is_array;
use function is_bool;
use function is_int;
use function is_string;
use function json_encode;

class WeaviateFilterCompiler extends FilterCompiler
{
    protected const STORE = WeaviateVectorStore::class;

    /**
     * Compile to a Weaviate "where" filter in REST format (batch delete).
     *
     * https://weaviate.io/developers/weaviate/api/graphql/filters
     *
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

            $conditions[] = $this->compileCondition($condition);
        }

        return count($conditions) === 1
            ? $conditions[0]
            : ['operator' => 'And', 'operands' => $conditions];
    }

    /**
     * The same filter rendered as a GraphQL argument (similarity search).
     */
    public function compileGraphQL(FilterGroup $filters): string
    {
        return $this->render($this->compile($filters));
    }

    /**
     * @return array<string, mixed>
     */
    protected function compileCondition(Filter $condition): array
    {
        $operator = match ($condition->operator) {
            FilterOperator::Eq => 'Equal',
            FilterOperator::Neq => 'NotEqual',
            FilterOperator::In => 'ContainsAny',
            FilterOperator::Gt => 'GreaterThan',
            FilterOperator::Gte => 'GreaterThanEqual',
            FilterOperator::Lt => 'LessThan',
            FilterOperator::Lte => 'LessThanEqual',
        };

        return [
            'path' => [$condition->field],
            'operator' => $operator,
            $this->valueKey($condition->value) => $condition->value,
        ];
    }

    protected function valueKey(mixed $value): string
    {
        $array = is_array($value);

        if (is_array($value)) {
            $value = $value[0];
        }

        if (is_string($value)) {
            return $array ? 'valueTextArray' : 'valueText';
        }

        if (is_int($value)) {
            return $array ? 'valueIntArray' : 'valueInt';
        }

        if (is_bool($value)) {
            return $array ? 'valueBooleanArray' : 'valueBoolean';
        }

        return $array ? 'valueNumberArray' : 'valueNumber';
    }

    /**
     * Render the REST-format filter as GraphQL syntax: keys and operator
     * enums unquoted, everything else JSON-encoded.
     */
    protected function render(mixed $value, string $key = ''): string
    {
        if (is_array($value)) {
            if (array_is_list($value)) {
                return '[' . implode(', ', array_map(fn (mixed $item): string => $this->render($item, $key), $value)) . ']';
            }

            $pairs = [];
            foreach ($value as $itemKey => $item) {
                $pairs[] = $itemKey . ': ' . $this->render($item, (string) $itemKey);
            }

            return '{' . implode(', ', $pairs) . '}';
        }

        if ($key === 'operator') {
            return (string) $value;
        }

        return (string) json_encode($value);
    }
}
