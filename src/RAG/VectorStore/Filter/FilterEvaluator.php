<?php

declare(strict_types=1);

namespace NeuronAI\RAG\VectorStore\Filter;

use NeuronAI\Exceptions\VectorStoreException;
use NeuronAI\RAG\Document;

use function array_key_exists;
use function is_array;
use function is_float;
use function is_int;
use function is_numeric;

/**
 * Matches a document's fields against a FilterGroup in PHP, for stores that
 * have no query language of their own (file and in-memory backends).
 */
class FilterEvaluator
{
    /**
     * @throws VectorStoreException
     */
    public function matchesDocument(FilterExpression $filters, Document $document): bool
    {
        return $this->matches($filters, [
            'content' => $document->getContent(),
            'sourceType' => $document->getSourceType(),
            'sourceName' => $document->getSourceName(),
            ...$document->getMetadata(),
        ]);
    }

    /**
     * @param array<string, mixed> $fields Flat field map: sourceType, sourceName, content, and metadata keys.
     * @throws VectorStoreException
     */
    public function matches(FilterExpression $filters, array $fields): bool
    {
        if ($filters instanceof RawFilter) {
            throw new VectorStoreException(
                "Raw filter targets {$filters->store}; it cannot be evaluated in PHP."
            );
        }

        if ($filters instanceof FilterGroup) {
            foreach ($filters->conditions() as $condition) {
                $matches = $this->matches($condition, $fields);

                if ($filters->operator() === FilterCombinator::And && !$matches) {
                    return false;
                }

                if ($filters->operator() === FilterCombinator::Or && $matches) {
                    return true;
                }
            }

            return $filters->operator() === FilterCombinator::And;
        }

        return $filters instanceof Filter && $this->matchesCondition($filters, $fields);
    }

    /**
     * @param array<string, mixed> $fields
     */
    protected function matchesCondition(Filter $condition, array $fields): bool
    {
        // A document without the field never matches — not even neq:
        // matching on absent data would silently widen a scoping filter.
        if (!array_key_exists($condition->field, $fields)) {
            return false;
        }

        $stored = $fields[$condition->field];
        $expected = $condition->value;

        return match ($condition->operator) {
            FilterOperator::Eq => $this->equals($stored, $expected),
            FilterOperator::Neq => !$this->equals($stored, $expected),
            FilterOperator::In => $this->isAnyOf($stored, (array) $expected),
            FilterOperator::Gt => is_numeric($stored) && $stored > $expected,
            FilterOperator::Gte => is_numeric($stored) && $stored >= $expected,
            FilterOperator::Lt => is_numeric($stored) && $stored < $expected,
            FilterOperator::Lte => is_numeric($stored) && $stored <= $expected,
            FilterOperator::ContainsAny => is_array($stored) && $this->containsAny($stored, (array) $expected),
            FilterOperator::ContainsAll => is_array($stored) && $this->containsAll($stored, (array) $expected),
        };
    }

    protected function equals(mixed $stored, mixed $expected): bool
    {
        // Integers and floats share portable numeric semantics; strings remain
        // strict so identifiers that look numeric can never collapse together.
        if ((is_int($stored) || is_float($stored)) && (is_int($expected) || is_float($expected))) {
            return $stored == $expected;
        }

        return $stored === $expected;
    }

    /**
     * @param array<string|int|float|bool> $values
     */
    protected function isAnyOf(mixed $stored, array $values): bool
    {
        foreach ($values as $value) {
            if ($this->equals($stored, $value)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string> $values
     */
    protected function containsAny(array $stored, array $values): bool
    {
        foreach ($values as $value) {
            if ($this->isAnyOf($value, $stored)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string> $values
     */
    protected function containsAll(array $stored, array $values): bool
    {
        foreach ($values as $value) {
            if (!$this->isAnyOf($value, $stored)) {
                return false;
            }
        }

        return true;
    }
}
