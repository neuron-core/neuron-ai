<?php

declare(strict_types=1);

namespace NeuronAI\RAG\VectorStore\Compilers;

use NeuronAI\RAG\VectorStore\Filter\Filter;
use NeuronAI\RAG\VectorStore\Filter\FilterOperator;
use NeuronAI\RAG\VectorStore\MongoDBVectorStore;
use function array_map;
use function in_array;

class MongoDBFilterCompiler extends MongoStyleFilterCompiler
{
    protected const STORE = MongoDBVectorStore::class;

    /**
     * Compile to a MongoDB match expression, usable both as a $vectorSearch
     * filter and as a deleteMany() filter.
     *
     * @return array<string, mixed>
     */
    protected function compileCondition(Filter $condition): array
    {
        if ($condition->operator === FilterOperator::ContainsAny) {
            return [$this->path($condition->field) => ['$in' => $condition->value]];
        }

        if ($condition->operator === FilterOperator::ContainsAll) {
            return ['$and' => array_map(
                fn (string $value): array => [$this->path($condition->field) => ['$eq' => $value]],
                $condition->value,
            )];
        }

        $comparison = [$this->operator($condition->operator) => $condition->value];

        if ($condition->operator === FilterOperator::Neq) {
            $comparison['$exists'] = true;
        }

        return [$this->path($condition->field) => $comparison];
    }

    /**
     * The store keeps custom metadata nested under a "metadata" document,
     * while the framework fields are top-level.
     */
    protected function path(string $field): string
    {
        return in_array($field, ['content', 'sourceType', 'sourceName'])
            ? $field
            : "metadata.{$field}";
    }
}
