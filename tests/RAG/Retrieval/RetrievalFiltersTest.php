<?php

declare(strict_types=1);

namespace NeuronAI\Tests\RAG\Retrieval;

use NeuronAI\Agent\Events\AgentStartEvent;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\RAG\Events\QueryPreProcessedEvent;
use NeuronAI\RAG\Retrieval\SimilarityRetrieval;
use NeuronAI\RAG\Schema\DocumentField;
use NeuronAI\RAG\Schema\DocumentSchema;
use NeuronAI\RAG\VectorStore\Filter\Filter;
use NeuronAI\RAG\VectorStore\Filter\FilterGroup;
use NeuronAI\RAG\VectorStore\Filter\FilterScope;
use NeuronAI\RAG\VectorStore\SearchRequest;
use NeuronAI\Testing\FakeEmbeddingsProvider;
use NeuronAI\Testing\FakeVectorStore;
use PHPUnit\Framework\TestCase;

use function end;

class RetrievalFiltersTest extends TestCase
{
    public function test_similarity_retrieval_searches_without_filters_by_default(): void
    {
        $store = new FakeVectorStore(schema: $this->filterSchema());
        $retrieval = new SimilarityRetrieval($store, new FakeEmbeddingsProvider());

        $retrieval->retrieve(new UserMessage('question'));

        $request = $this->lastSearchRequest($store);
        $this->assertNull($request->filters);
    }

    public function test_similarity_retrieval_ands_static_and_incoming_filters(): void
    {
        $store = new FakeVectorStore(schema: $this->filterSchema());
        $retrieval = new SimilarityRetrieval(
            $store,
            new FakeEmbeddingsProvider(),
            filters: FilterGroup::and(Filter::eq('tenant', 'acme')),
        );

        $retrieval->retrieve(
            new UserMessage('question'),
            FilterGroup::and(Filter::eq('lang', 'en')),
        );

        $request = $this->lastSearchRequest($store);
        $this->assertInstanceOf(FilterGroup::class, $request->filters);

        $conditions = $request->filters->conditions();
        $this->assertCount(2, $conditions);
        $tenant = $conditions[0];
        $language = $conditions[1];
        $this->assertInstanceOf(Filter::class, $tenant);
        $this->assertInstanceOf(Filter::class, $language);
        $this->assertSame('tenant', $tenant->field);
        $this->assertSame('lang', $language->field);
    }

    public function test_incoming_filters_alone_reach_the_store(): void
    {
        $store = new FakeVectorStore(schema: $this->filterSchema());
        $retrieval = new SimilarityRetrieval($store, new FakeEmbeddingsProvider());

        $retrieval->retrieve(
            new UserMessage('question'),
            FilterGroup::and(Filter::eq('lang', 'en')),
        );

        $request = $this->lastSearchRequest($store);
        $this->assertInstanceOf(FilterGroup::class, $request->filters);
        $this->assertCount(1, $request->filters->conditions());
    }

    public function test_event_filters_accumulate_by_and(): void
    {
        $event = new QueryPreProcessedEvent(new UserMessage('question'), new AgentStartEvent());

        $this->assertNull($event->getFilters());

        $event->addFilters(FilterGroup::and(Filter::eq('tenant', 'acme')));
        $event->addFilters(FilterGroup::and(Filter::eq('lang', 'en')));

        $filters = $event->getFilters();
        $this->assertInstanceOf(FilterGroup::class, $filters);
        $this->assertCount(2, $filters->conditions());
    }

    public function test_scope_merge_preserves_nested_query_logic(): void
    {
        $filters = FilterScope::merge(
            Filter::eq('tenant', 'acme'),
            FilterGroup::anyOf(Filter::eq('lang', 'en'), Filter::eq('lang', 'it')),
        )?->expression();

        $this->assertInstanceOf(FilterGroup::class, $filters);
        $this->assertSame('and', $filters->operator()->value);
        $nested = $filters->conditions()[1];
        $this->assertInstanceOf(FilterGroup::class, $nested);
        $this->assertSame('or', $nested->operator()->value);
    }

    protected function lastSearchRequest(FakeVectorStore $store): SearchRequest
    {
        $recorded = $store->getRecorded();
        $last = end($recorded);

        $this->assertSame('search', $last['method']);
        $this->assertInstanceOf(SearchRequest::class, $last['args'][0]);

        return $last['args'][0];
    }

    protected function filterSchema(): DocumentSchema
    {
        return DocumentSchema::of(
            DocumentField::string('tenant')->filterable(),
            DocumentField::string('lang')->filterable(),
        );
    }
}
