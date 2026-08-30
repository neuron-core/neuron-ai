<?php

declare(strict_types=1);

namespace NeuronAI\RAG\VectorStore\Filter;

use BackedEnum;
use DateTimeInterface;
use NeuronAI\Exceptions\VectorStoreException;
use NeuronAI\RAG\Schema\DocumentField;
use NeuronAI\RAG\Schema\DocumentSchema;

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
class Filter implements FilterExpression
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

    public static function eq(
        string|DocumentField $field,
        string|int|float|bool|BackedEnum|DateTimeInterface $value,
    ): self {
        return self::comparison($field, FilterOperator::Eq, self::normalize($value));
    }

    public static function neq(
        string|DocumentField $field,
        string|int|float|bool|BackedEnum|DateTimeInterface $value,
    ): self {
        return self::comparison($field, FilterOperator::Neq, self::normalize($value));
    }

    /**
     * @param array<string|int|float|bool|BackedEnum|DateTimeInterface> $values
     */
    public static function in(string|DocumentField $field, array $values): self
    {
        $fieldName = self::fieldName($field);

        if ($values === []) {
            throw new VectorStoreException("Filter \"in\" on field \"{$fieldName}\" requires at least one value.");
        }

        $normalized = [];
        foreach ($values as $value) {
            if (!is_string($value) && !is_int($value) && !is_float($value) && !is_bool($value)
                && !$value instanceof BackedEnum && !$value instanceof DateTimeInterface) {
                throw new VectorStoreException("Filter \"in\" on field \"{$fieldName}\" accepts scalar values only.");
            }
            $normalized[] = self::normalize($value);
        }

        return self::comparison($field, FilterOperator::In, $normalized);
    }

    public static function gt(string|DocumentField $field, int|float|BackedEnum|DateTimeInterface $value): self
    {
        return self::range($field, FilterOperator::Gt, $value);
    }

    public static function gte(string|DocumentField $field, int|float|BackedEnum|DateTimeInterface $value): self
    {
        return self::range($field, FilterOperator::Gte, $value);
    }

    public static function lt(string|DocumentField $field, int|float|BackedEnum|DateTimeInterface $value): self
    {
        return self::range($field, FilterOperator::Lt, $value);
    }

    public static function lte(string|DocumentField $field, int|float|BackedEnum|DateTimeInterface $value): self
    {
        return self::range($field, FilterOperator::Lte, $value);
    }

    /**
     * @param array<string|BackedEnum> $values
     */
    public static function containsAny(string|DocumentField $field, array $values): self
    {
        return self::contains($field, FilterOperator::ContainsAny, $values);
    }

    /**
     * @param array<string|BackedEnum> $values
     */
    public static function containsAll(string|DocumentField $field, array $values): self
    {
        return self::contains($field, FilterOperator::ContainsAll, $values);
    }

    public static function where(
        string|DocumentField $field,
        string|int|float|bool|BackedEnum|DateTimeInterface $value,
    ): Criteria {
        return Criteria::from(self::eq($field, $value));
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

    public function toArray(): array
    {
        return [
            'operator' => $this->operator->value,
            'field' => $this->field,
            'value' => $this->value,
        ];
    }

    protected static function comparison(
        string|DocumentField $field,
        FilterOperator $operator,
        string|int|float|bool|array $value,
    ): self {
        $filter = new self(self::fieldName($field), $operator, $value);
        self::validateSchemaField($filter, $field);

        return $filter;
    }

    protected static function range(
        string|DocumentField $field,
        FilterOperator $operator,
        int|float|BackedEnum|DateTimeInterface $value,
    ): self {
        $value = self::normalize($value);

        if (!is_int($value) && !is_float($value)) {
            throw new VectorStoreException(
                "Filter \"{$operator->value}\" on field \"" . self::fieldName($field) . '" requires a numeric value.'
            );
        }

        return self::comparison($field, $operator, $value);
    }

    /**
     * @param array<string|BackedEnum> $values
     */
    protected static function contains(
        string|DocumentField $field,
        FilterOperator $operator,
        array $values,
    ): self {
        $fieldName = self::fieldName($field);

        if ($values === []) {
            throw new VectorStoreException(
                "Filter \"{$operator->value}\" on field \"{$fieldName}\" requires at least one value."
            );
        }

        $normalized = [];
        foreach ($values as $value) {
            $value = self::normalize($value);
            if (!is_string($value)) {
                throw new VectorStoreException(
                    "Filter \"{$operator->value}\" on field \"{$fieldName}\" accepts string values only."
                );
            }
            $normalized[] = $value;
        }

        return self::comparison($field, $operator, $normalized);
    }

    protected static function normalize(
        string|int|float|bool|BackedEnum|DateTimeInterface $value,
    ): string|int|float|bool {
        if ($value instanceof BackedEnum) {
            return $value->value;
        }

        if ($value instanceof DateTimeInterface) {
            return $value->getTimestamp();
        }

        return $value;
    }

    protected static function fieldName(string|DocumentField $field): string
    {
        return $field instanceof DocumentField ? $field->getName() : $field;
    }

    protected static function validateSchemaField(self $filter, string|DocumentField $field): void
    {
        if (!$field instanceof DocumentField) {
            return;
        }

        (new FilterValidator())->validate(FilterGroup::allOf($filter), DocumentSchema::of($field));
    }
}
