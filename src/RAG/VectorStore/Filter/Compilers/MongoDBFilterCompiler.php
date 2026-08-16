<?php

declare(strict_types=1);

namespace NeuronAI\RAG\VectorStore\Filter\Compilers;

use NeuronAI\RAG\VectorStore\Filter\FilterGroup;
use NeuronAI\RAG\VectorStore\Filter\FilterOperator;
use NeuronAI\RAG\VectorStore\Filter\RawFilter;
use NeuronAI\RAG\VectorStore\MongoDBVectorStore;

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
    public function compile(FilterGroup $filters): array
    {
        $conditions = [];

        foreach ($filters->conditions() as $condition) {
            if ($condition instanceof RawFilter) {
                $this->assertTargetsThisStore($condition);
                $conditions[] = (array) $condition->fragment;
                continue;
            }

            $path = $this->path($condition->field);
            $comparison = [$this->operator($condition->operator) => $condition->value];

            if ($condition->operator === FilterOperator::Neq) {
                $comparison['$exists'] = true;
            }

            $conditions[] = [$path => $comparison];
        }

        return ['$and' => $conditions];
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
