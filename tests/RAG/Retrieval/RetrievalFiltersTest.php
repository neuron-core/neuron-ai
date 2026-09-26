<?php

declare(strict_types=1);

namespace NeuronAI\Tests\RAG\Retrieval;

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
use NeuronAI\Testing\VectorStoreRecord;
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

        $this->assertSame([
            'operator' => 'and',
            'conditions' => [
                ['operator' => 'eq', 'field' => 'tenant', 'value' => 'acme'],
                ['operator' => 'eq', 'field' => 'lang', 'value' => 'en'],
            ],
        ], $this->lastSearchRequest($store)->filters?->toArray());
    }

    public function test_incoming_filters_alone_reach_the_store(): void
    {
        $store = new FakeVectorStore(schema: $this->filterSchema());
        $retrieval = new SimilarityRetrieval($store, new FakeEmbeddingsProvider());

        $retrieval->retrieve(
            new UserMessage('question'),
            FilterGroup::and(Filter::eq('lang', 'en')),
        );

        $this->assertSame([
            'operator' => 'and',
            'conditions' => [['operator' => 'eq', 'field' => 'lang', 'value' => 'en']],
        ], $this->lastSearchRequest($store)->filters?->toArray());
    }

    public function test_event_filters_accumulate_by_and(): void
    {
        $this->assertNull((new QueryPreProcessedEvent(new UserMessage('question')))->getFilters());

        $event = (new QueryPreProcessedEvent(new UserMessage('question')))
            ->addFilters(FilterGroup::and(Filter::eq('tenant', 'acme')))
            ->addFilters(FilterGroup::and(Filter::eq('lang', 'en')));

        $this->assertSame([
            'operator' => 'and',
            'conditions' => [
                ['operator' => 'eq', 'field' => 'tenant', 'value' => 'acme'],
                ['operator' => 'eq', 'field' => 'lang', 'value' => 'en'],
            ],
        ], $event->getFilters()?->toArray());
    }

    public function test_a_single_injected_filter_is_forwarded_unchanged(): void
    {
        $filter = Filter::eq('tenant', 'acme');

        $this->assertSame($filter, (new QueryPreProcessedEvent(new UserMessage('question')))->addFilters($filter)->getFilters());
    }

    public function test_a_later_or_filter_is_nested_and_cannot_relax_an_earlier_one(): void
    {
        $event = (new QueryPreProcessedEvent(new UserMessage('question')))
            ->addFilters(Filter::eq('tenant', 'acme'))
            ->addFilters(FilterGroup::anyOf(Filter::eq('tenant', 'globex'), Filter::eq('lang', 'en')));

        $filters = $event->getFilters();
        $this->assertInstanceOf(FilterGroup::class, $filters);
        $this->assertSame('and', $filters->operator()->value);
        $this->assertSame(['operator' => 'eq', 'field' => 'tenant', 'value' => 'acme'], $filters->conditions()[0]->toArray());
        $this->assertSame('or', $filters->conditions()[1]->toArray()['operator']);
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

        $this->assertInstanceOf(VectorStoreRecord::class, $last);
        $this->assertInstanceOf(SearchRequest::class, $last->request);

        return $last->request;
    }

    protected function filterSchema(): DocumentSchema
    {
        return DocumentSchema::of(
            DocumentField::string('tenant')->filterable(),
            DocumentField::string('lang')->filterable(),
        );
    }
}
