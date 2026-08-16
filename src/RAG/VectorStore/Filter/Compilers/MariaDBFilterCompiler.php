<?php

declare(strict_types=1);

namespace NeuronAI\RAG\VectorStore\Filter\Compilers;

use NeuronAI\Exceptions\VectorStoreException;
use NeuronAI\RAG\VectorStore\Filter\Filter;
use NeuronAI\RAG\VectorStore\Filter\FilterGroup;
use NeuronAI\RAG\VectorStore\Filter\FilterOperator;
use NeuronAI\RAG\VectorStore\Filter\RawFilter;
use NeuronAI\RAG\VectorStore\MariaDBVectorStore;
use NeuronAI\RAG\Schema\DocumentFieldType;
use NeuronAI\RAG\Schema\DocumentSchema;

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
    public function compile(FilterGroup $filters): array
    {
        $clauses = [];
        $bindings = [];
        $index = 0;

        foreach ($filters->conditions() as $condition) {
            if ($condition instanceof RawFilter) {
                $this->assertTargetsThisStore($condition);
                $clauses[] = '(' . (string) $condition->fragment . ')';
                continue;
            }

            $clauses[] = $this->compileCondition($condition, $bindings, $index);
        }

        return ['sql' => implode(' AND ', $clauses), 'bindings' => $bindings];
    }

    /**
     * @param array<string, string|int|float> $bindings
     */
    protected function compileCondition(Filter $condition, array &$bindings, int &$index): string
    {
        $column = $this->column($condition->field);

        if ($condition->operator === FilterOperator::In) {
            $placeholders = [];
            foreach ((array) $condition->value as $value) {
                $placeholder = ':f' . $index++;
                $placeholders[] = $placeholder;
                $bindings[$placeholder] = $this->bindable($value);
            }

            return "{$column} IN (" . implode(', ', $placeholders) . ')';
        }

        $placeholder = ':f' . $index++;
        $bindings[$placeholder] = $this->bindable($condition->value);

        return match ($condition->operator) {
            FilterOperator::Eq => "{$column} = {$placeholder}",
            FilterOperator::Neq => "{$column} <> {$placeholder}",
            FilterOperator::Gt => "{$column} > {$placeholder}",
            FilterOperator::Gte => "{$column} >= {$placeholder}",
            FilterOperator::Lt => "{$column} < {$placeholder}",
            FilterOperator::Lte => "{$column} <= {$placeholder}",
        };
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
