<?php

declare(strict_types=1);

namespace NeuronAI\RAG\VectorStore\Filter;

use NeuronAI\Exceptions\VectorStoreException;

use function array_filter;

/**
 * A conjunction of filter conditions: a document matches only if every
 * condition matches.
 *
 * Nested groups are flattened on construction — AND of AND is a single
 * conjunction — so scopes compose by appending:
 * FilterGroup::and($frameworkScope, $userFilters) can only narrow what an
 * outer scope allows, never widen it.
 */
class FilterGroup
{
    /**
     * @var array<Filter|RawFilter>
     */
    protected array $conditions;

    protected function __construct(Filter|RawFilter ...$conditions)
    {
        $this->conditions = $conditions;
    }

    public static function and(Filter|RawFilter|FilterGroup ...$conditions): self
    {
        $flattened = [];

        foreach ($conditions as $condition) {
            if ($condition instanceof FilterGroup) {
                $flattened = [...$flattened, ...$condition->conditions()];
            } else {
                $flattened[] = $condition;
            }
        }

        if ($flattened === []) {
            throw new VectorStoreException('A filter group requires at least one condition.');
        }

        return new self(...$flattened);
    }

    /**
     * Null-tolerant AND: combines whichever groups exist, returns null when
     * none do. This is how a retrieval strategy honors incoming per-run
     * filters alongside its own static scope.
     */
    public static function merge(?FilterGroup ...$groups): ?self
    {
        $groups = array_filter($groups);

        return $groups === [] ? null : self::and(...$groups);
    }

    /**
     * @return array<Filter|RawFilter>
     */
    public function conditions(): array
    {
        return $this->conditions;
    }
}
