<?php

declare(strict_types=1);

namespace NeuronAI\RAG\VectorStore\Filter;

use NeuronAI\Exceptions\VectorStoreException;

use function is_bool;
use function is_float;
use function is_int;
use function is_string;

/**
 * A single portable comparison, combined with others through {@see FilterGroup}.
 *
 * The vocabulary is deliberately small: only comparisons every backend can
 * honor with identical semantics. Values are scalars — null has no portable
 * missing-vs-null semantics across backends — and range comparisons are
 * numeric-only, because not every backend can range-compare strings. Anything
 * beyond this vocabulary goes through {@see Filter::raw()} as a
 * backend-native fragment.
 */
class Filter
{
    /**
     * @param string|int|float|bool|array<string|int|float|bool> $value
     */
    protected function __construct(
        public readonly string $field,
        public readonly FilterOperator $operator,
        public readonly string|int|float|bool|array $value,
    ) {
        if ($field === '') {
            throw new VectorStoreException('Filter field name cannot be empty.');
        }
    }

    public static function eq(string $field, string|int|float|bool $value): self
    {
        return new self($field, FilterOperator::Eq, $value);
    }

    public static function neq(string $field, string|int|float|bool $value): self
    {
        return new self($field, FilterOperator::Neq, $value);
    }

    /**
     * @param array<string|int|float|bool> $values
     */
    public static function in(string $field, array $values): self
    {
        if ($values === []) {
            throw new VectorStoreException("Filter \"in\" on field \"{$field}\" requires at least one value.");
        }

        foreach ($values as $value) {
            if (!is_string($value) && !is_int($value) && !is_float($value) && !is_bool($value)) {
                throw new VectorStoreException("Filter \"in\" on field \"{$field}\" accepts scalar values only.");
            }
        }

        return new self($field, FilterOperator::In, $values);
    }

    public static function gt(string $field, int|float $value): self
    {
        return new self($field, FilterOperator::Gt, $value);
    }

    public static function gte(string $field, int|float $value): self
    {
        return new self($field, FilterOperator::Gte, $value);
    }

    public static function lt(string $field, int|float $value): self
    {
        return new self($field, FilterOperator::Lt, $value);
    }

    public static function lte(string $field, int|float $value): self
    {
        return new self($field, FilterOperator::Lte, $value);
    }

    /**
     * Escape hatch for backend capabilities outside the portable vocabulary.
     * The fragment is passed through verbatim by the tagged store class and
     * rejected by every other store.
     *
     * @param class-string $store
     */
    public static function raw(string $store, mixed $fragment): RawFilter
    {
        return new RawFilter($store, $fragment);
    }
}
