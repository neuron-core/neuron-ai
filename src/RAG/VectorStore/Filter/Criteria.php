<?php

declare(strict_types=1);

namespace NeuronAI\RAG\VectorStore\Filter;

use BackedEnum;
use DateTimeInterface;
use NeuronAI\Exceptions\VectorStoreException;
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

    /**
     * @throws VectorStoreException
     */
    public function where(
        string|DocumentField $field,
        string|int|float|bool|BackedEnum|DateTimeInterface $value,
    ): self {
        return $this->with(Filter::eq($field, $value));
    }

    /**
     * @throws VectorStoreException
     */
    public function whereNot(
        string|DocumentField $field,
        string|int|float|bool|BackedEnum|DateTimeInterface $value,
    ): self {
        return $this->with(Filter::neq($field, $value));
    }

    /**
     * @param array<string|int|float|bool|BackedEnum|DateTimeInterface> $values
     * @throws VectorStoreException
     */
    public function whereIn(string|DocumentField $field, array $values): self
    {
        return $this->with(Filter::in($field, $values));
    }

    /**
     * @throws VectorStoreException
     */
    public function whereGreaterThan(
        string|DocumentField $field,
        int|float|BackedEnum|DateTimeInterface $value,
    ): self {
        return $this->with(Filter::gt($field, $value));
    }

    /**
     * @throws VectorStoreException
     */
    public function whereGreaterThanOrEqual(
        string|DocumentField $field,
        int|float|BackedEnum|DateTimeInterface $value,
    ): self {
        return $this->with(Filter::gte($field, $value));
    }

    /**
     * @throws VectorStoreException
     */
    public function whereLessThan(
        string|DocumentField $field,
        int|float|BackedEnum|DateTimeInterface $value,
    ): self {
        return $this->with(Filter::lt($field, $value));
    }

    /**
     * @throws VectorStoreException
     */
    public function whereLessThanOrEqual(
        string|DocumentField $field,
        int|float|BackedEnum|DateTimeInterface $value,
    ): self {
        return $this->with(Filter::lte($field, $value));
    }

    /**
     * @param array<string|BackedEnum> $values
     * @throws VectorStoreException
     */
    public function whereContainsAny(string|DocumentField $field, array $values): self
    {
        return $this->with(Filter::containsAny($field, $values));
    }

    /**
     * @param array<string|BackedEnum> $values
     * @throws VectorStoreException
     */
    public function whereContainsAll(string|DocumentField $field, array $values): self
    {
        return $this->with(Filter::containsAll($field, $values));
    }

    /**
     * @throws VectorStoreException
     */
    public function whereAny(FilterExpression ...$expressions): self
    {
        return $this->with(FilterGroup::anyOf(...$expressions));
    }

    public function with(FilterExpression $expression): self
    {
        return new self(FilterCombinator::And, ...[...$this->conditions(), $expression]);
    }
}
