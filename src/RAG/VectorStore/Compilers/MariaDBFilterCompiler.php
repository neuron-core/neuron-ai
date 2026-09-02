<?php

declare(strict_types=1);

namespace NeuronAI\RAG\VectorStore\Compilers;

use LogicException;
use NeuronAI\Exceptions\VectorStoreException;
use NeuronAI\RAG\Schema\DocumentFieldType;
use NeuronAI\RAG\Schema\DocumentSchema;
use NeuronAI\RAG\VectorStore\Filter\Filter;
use NeuronAI\RAG\VectorStore\Filter\FilterCombinator;
use NeuronAI\RAG\VectorStore\Filter\FilterExpression;
use NeuronAI\RAG\VectorStore\Filter\FilterGroup;
use NeuronAI\RAG\VectorStore\Filter\FilterOperator;
use NeuronAI\RAG\VectorStore\Filter\RawFilter;
use NeuronAI\RAG\VectorStore\MariaDBVectorStore;

use function implode;
use function in_array;
use function is_bool;
use function preg_match;

class MariaDBFilterCompiler extends FilterCompiler
{
    protected const STORE = MariaDBVectorStore::class;

    protected DocumentSchema $schema;

    public function __construct(?DocumentSchema $schema = null)
    {
        $this->schema = $schema ?? DocumentSchema::default();
    }

    /**
     * Compile to a SQL WHERE clause with named bindings (":f0", ":f1", ...),
     * safe to merge with a statement's own named parameters.
     *
     * @return array{sql: string, bindings: array<string, string|int|float>}
     */
    public function compile(FilterExpression $filters): array
    {
        $bindings = [];
        $index = 0;

        return [
            'sql' => $this->compileExpression($filters, $bindings, $index, true),
            'bindings' => $bindings,
        ];
    }

    /**
     * @param array<string, string|int|float> $bindings
     */
    protected function compileExpression(
        FilterExpression $filters,
        array &$bindings,
        int &$index,
        bool $root = false,
    ): string {
        if ($filters instanceof RawFilter) {
            $this->assertTargetsThisStore($filters);
            return '(' . $filters->fragment . ')';
        }

        if ($filters instanceof Filter) {
            return $this->compileCondition($filters, $bindings, $index);
        }

        if (!$filters instanceof FilterGroup) {
            throw new LogicException('Unsupported filter expression.');
        }

        $separator = $filters->operator() === FilterCombinator::And ? ' AND ' : ' OR ';
        $clauses = [];
        foreach ($filters->conditions() as $condition) {
            $clauses[] = $this->compileExpression($condition, $bindings, $index);
        }
        $compiled = implode($separator, $clauses);

        return $root ? $compiled : '(' . $compiled . ')';
    }

    /**
     * @param array<string, string|int|float> $bindings
     */
    protected function compileCondition(Filter $condition, array &$bindings, int &$index): string
    {
        $column = $this->column($condition->field);

        return match ($condition->operator) {
            FilterOperator::Eq => $this->compileComparison($column, '=', $condition->value, $bindings, $index),
            FilterOperator::Neq => $this->compileComparison($column, '<>', $condition->value, $bindings, $index),
            FilterOperator::Gt => $this->compileComparison($column, '>', $condition->value, $bindings, $index),
            FilterOperator::Gte => $this->compileComparison($column, '>=', $condition->value, $bindings, $index),
            FilterOperator::Lt => $this->compileComparison($column, '<', $condition->value, $bindings, $index),
            FilterOperator::Lte => $this->compileComparison($column, '<=', $condition->value, $bindings, $index),
            FilterOperator::In => $this->compileIn($column, (array) $condition->value, $bindings, $index),
            FilterOperator::ContainsAny => $this->compileContains($condition, ' OR ', $bindings, $index),
            FilterOperator::ContainsAll => $this->compileContains($condition, ' AND ', $bindings, $index),
        };
    }

    /**
     * @param array<string, string|int|float> $bindings
     */
    protected function compileComparison(
        string $column,
        string $operator,
        mixed $value,
        array &$bindings,
        int &$index,
    ): string {
        $placeholder = ':f' . $index++;
        $bindings[$placeholder] = $this->bindable($value);

        return "{$column} {$operator} {$placeholder}";
    }

    /**
     * @param array<mixed> $values
     * @param array<string, string|int|float> $bindings
     */
    protected function compileIn(string $column, array $values, array &$bindings, int &$index): string
    {
        $placeholders = [];
        foreach ($values as $value) {
            $placeholder = ':f' . $index++;
            $placeholders[] = $placeholder;
            $bindings[$placeholder] = $this->bindable($value);
        }

        return "{$column} IN (" . implode(', ', $placeholders) . ')';
    }

    /**
     * @param array<string, string|int|float> $bindings
     */
    protected function compileContains(
        Filter $condition,
        string $separator,
        array &$bindings,
        int &$index,
    ): string {
        $clauses = [];
        foreach ($condition->value as $value) {
            $placeholder = ':f' . $index++;
            $bindings[$placeholder] = $this->bindable($value);
            $clauses[] = "JSON_CONTAINS(metadata, JSON_QUOTE({$placeholder}), '$.{$condition->field}')";
        }

        return '(' . implode($separator, $clauses) . ')';
    }

    /**
     * The framework fields are real columns; everything else lives in the
     * JSON metadata column. The field name is interpolated into SQL, so
     * anything but a plain identifier is refused.
     */
    protected function column(string $field): string
    {
        if (in_array($field, ['content', 'sourceType', 'sourceName'])) {
            return $field;
        }

        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $field)) {
            throw new VectorStoreException("Metadata field \"{$field}\" is not a valid identifier for a SQL filter.");
        }

        $expression = "JSON_VALUE(metadata, '$.{$field}')";
        $type = $this->schema->getField($field)?->getType();

        return match ($type) {
            DocumentFieldType::Integer => "CAST({$expression} AS SIGNED)",
            DocumentFieldType::Float => "CAST({$expression} AS DECIMAL(65, 30))",
            DocumentFieldType::Boolean => "CAST({$expression} AS UNSIGNED)",
            default => $expression,
        };
    }

    protected function bindable(mixed $value): string|int|float
    {
        if (is_bool($value)) {
            return $value ? 1 : 0;
        }

        /** @var string|int|float $value */
        return $value;
    }
}
