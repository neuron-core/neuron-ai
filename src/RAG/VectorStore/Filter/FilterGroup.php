<?php

declare(strict_types=1);

namespace NeuronAI\RAG\VectorStore\Filter;

use NeuronAI\Exceptions\VectorStoreException;

use function array_map;

/**
 * A portable boolean expression. Groups with the same operator flatten;
 * differently nested operators retain their boundaries.
 */
class FilterGroup implements FilterExpression
{
    /**
     * @var array<FilterExpression>
     */
    protected array $conditions;

    protected function __construct(
        protected readonly FilterCombinator $operator,
        FilterExpression ...$conditions,
    ) {
        $this->conditions = $conditions;
    }

    public static function and(FilterExpression ...$conditions): self
    {
        return self::allOf(...$conditions);
    }

    public static function or(FilterExpression ...$conditions): self
    {
        return self::anyOf(...$conditions);
    }

    public static function allOf(FilterExpression ...$conditions): self
    {
        return self::group(FilterCombinator::And, ...$conditions);
    }

    public static function anyOf(FilterExpression ...$conditions): self
    {
        return self::group(FilterCombinator::Or, ...$conditions);
    }

    protected static function group(FilterCombinator $operator, FilterExpression ...$conditions): self
    {
        $flattened = [];

        foreach ($conditions as $condition) {
            if ($condition instanceof FilterGroup && $condition->operator() === $operator) {
                $flattened = [...$flattened, ...$condition->conditions()];
            } else {
                $flattened[] = $condition;
            }
        }

        if ($flattened === []) {
            throw new VectorStoreException('A filter group requires at least one condition.');
        }

        return new self($operator, ...$flattened);
    }

    /**
     * Null-tolerant AND: combines whichever groups exist, returns null when
     * none do. This is how a retrieval strategy honors incoming per-run
     * filters alongside its own static scope.
     */
    public static function merge(?FilterExpression ...$groups): ?self
    {
        $expression = FilterScope::merge(...$groups)?->expression();

        return $expression instanceof FilterExpression ? self::allOf($expression) : null;
    }

    public function operator(): FilterCombinator
    {
        return $this->operator;
    }

    /**
     * @return array<FilterExpression>
     */
    public function conditions(): array
    {
        return $this->conditions;
    }

    public function toArray(): array
    {
        return [
            'operator' => $this->operator->value,
            'conditions' => array_map(
                static fn (FilterExpression $condition): array => $condition->toArray(),
                $this->conditions,
            ),
        ];
    }
}
