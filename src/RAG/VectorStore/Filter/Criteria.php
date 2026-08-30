<?php

declare(strict_types=1);

namespace NeuronAI\RAG\VectorStore\Filter;

use BackedEnum;
use DateTimeInterface;
use NeuronAI\RAG\Schema\DocumentField;

/**
 * Fluent AND-oriented facade over the immutable filter expression tree.
 */
class Criteria extends FilterGroup
{
    public static function from(FilterExpression $expression): self
    {
        if ($expression instanceof FilterGroup && $expression->operator() === FilterCombinator::And) {
            return new self(FilterCombinator::And, ...$expression->conditions());
        }

        return new self(FilterCombinator::And, $expression);
    }

    public function where(
        string|DocumentField $field,
        string|int|float|bool|BackedEnum|DateTimeInterface $value,
    ): self {
        return $this->with(Filter::eq($field, $value));
    }

    public function whereNot(
        string|DocumentField $field,
        string|int|float|bool|BackedEnum|DateTimeInterface $value,
    ): self {
        return $this->with(Filter::neq($field, $value));
    }

    /**
     * @param array<string|int|float|bool|BackedEnum|DateTimeInterface> $values
     */
    public function whereIn(string|DocumentField $field, array $values): self
    {
        return $this->with(Filter::in($field, $values));
    }

    public function whereGreaterThan(
        string|DocumentField $field,
        int|float|BackedEnum|DateTimeInterface $value,
    ): self {
        return $this->with(Filter::gt($field, $value));
    }

    public function whereGreaterThanOrEqual(
        string|DocumentField $field,
        int|float|BackedEnum|DateTimeInterface $value,
    ): self {
        return $this->with(Filter::gte($field, $value));
    }

    public function whereLessThan(
        string|DocumentField $field,
        int|float|BackedEnum|DateTimeInterface $value,
    ): self {
        return $this->with(Filter::lt($field, $value));
    }

    public function whereLessThanOrEqual(
        string|DocumentField $field,
        int|float|BackedEnum|DateTimeInterface $value,
    ): self {
        return $this->with(Filter::lte($field, $value));
    }

    /**
     * @param array<string|BackedEnum> $values
     */
    public function whereContainsAny(string|DocumentField $field, array $values): self
    {
        return $this->with(Filter::containsAny($field, $values));
    }

    /**
     * @param array<string|BackedEnum> $values
     */
    public function whereContainsAll(string|DocumentField $field, array $values): self
    {
        return $this->with(Filter::containsAll($field, $values));
    }

    public function whereAny(FilterExpression ...$expressions): self
    {
        return $this->with(FilterGroup::anyOf(...$expressions));
    }

    public function with(FilterExpression $expression): self
    {
        return new self(FilterCombinator::And, ...[...$this->conditions(), $expression]);
    }
}
