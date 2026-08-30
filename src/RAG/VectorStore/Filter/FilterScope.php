<?php

declare(strict_types=1);

namespace NeuronAI\RAG\VectorStore\Filter;

use function array_filter;
use function array_values;
use function count;

/**
 * Mandatory retrieval constraints. Independent scopes always compose with AND,
 * while each scope may contain its own nested boolean expression.
 */
final class FilterScope
{
    protected function __construct(protected readonly FilterExpression $expression)
    {
    }

    public static function merge(?FilterExpression ...$expressions): ?self
    {
        $expressions = array_values(array_filter(
            $expressions,
            static fn (?FilterExpression $expression): bool => $expression instanceof FilterExpression,
        ));

        if ($expressions === []) {
            return null;
        }

        return new self(count($expressions) === 1 ? $expressions[0] : FilterGroup::allOf(...$expressions));
    }

    public function expression(): FilterExpression
    {
        return $this->expression;
    }
}
