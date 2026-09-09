<?php

declare(strict_types=1);

namespace NeuronAI\RAG\Events;

use NeuronAI\Chat\Messages\Message;
use NeuronAI\RAG\VectorStore\Filter\FilterExpression;
use NeuronAI\RAG\VectorStore\Filter\FilterScope;
use NeuronAI\Workflow\Events\Event;

/**
 * Event emitted after query preprocessing.
 *
 * Triggers document retrieval from vector store. It is also the injection
 * channel for retrieval filters: middleware (in before() on the retrieval
 * node) and preceding nodes add constraints here. The event is born fresh
 * every run, so an injected filter can never leak into the next run.
 */
class QueryPreProcessedEvent implements Event
{
    protected ?FilterScope $scope = null;

    /**
     * @param Message $query The (possibly transformed) query
     */
    public function __construct(
        public readonly Message $query,
    ) {
    }

    /**
     * Constrain this run's retrieval. Filters only accumulate (AND):
     * an injected filter can narrow the search, never replace or relax
     * what another injector scoped.
     */
    public function addFilters(FilterExpression $filters): self
    {
        $this->scope = FilterScope::merge($this->scope?->expression(), $filters);

        return $this;
    }

    public function getFilters(): ?FilterExpression
    {
        return $this->scope?->expression();
    }
}
