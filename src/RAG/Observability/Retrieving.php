<?php

declare(strict_types=1);

namespace NeuronAI\RAG\Observability;

use NeuronAI\Chat\Messages\Message;
use NeuronAI\Observability\ObservabilityEvent;
use NeuronAI\RAG\VectorStore\Filter\Filter;
use NeuronAI\RAG\VectorStore\Filter\FilterExpression;
use NeuronAI\RAG\VectorStore\Filter\FilterGroup;
use NeuronAI\RAG\VectorStore\Filter\RawFilter;

use function array_map;

class Retrieving extends ObservabilityEvent
{
    public function __construct(
        public Message $question,
        public ?FilterExpression $filters = null,
    ) {
    }

    public function name(): string
    {
        return 'rag-retrieving';
    }

    public function toArray(): array
    {
        return [
            'question' => $this->question->jsonSerialize(),
            'filters' => $this->filters instanceof FilterExpression ? $this->structure($this->filters) : null,
        ];
    }

    /**
     * Fields, operators and nesting, without comparison values or raw
     * fragments: those can carry authorization data.
     *
     * @return array<string, mixed>
     */
    protected function structure(FilterExpression $filter): array
    {
        if ($filter instanceof Filter) {
            return ['operator' => $filter->operator->value, 'field' => $filter->field];
        }

        if ($filter instanceof RawFilter) {
            return ['operator' => 'raw', 'store' => $filter->store];
        }

        if ($filter instanceof FilterGroup) {
            return [
                'operator' => $filter->operator()->value,
                'conditions' => array_map($this->structure(...), $filter->conditions()),
            ];
        }

        return ['operator' => 'unsupported', 'class' => $filter::class];
    }
}
