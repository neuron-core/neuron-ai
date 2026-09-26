<?php

declare(strict_types=1);

namespace NeuronAI\Tests\RAG\Nodes;

use NeuronAI\Agent\AgentState;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\Events\QueryPreProcessedEvent;
use NeuronAI\RAG\Nodes\RetrievalNode;
use NeuronAI\RAG\Retrieval\SimilarityRetrieval;
use NeuronAI\RAG\Schema\DocumentField;
use NeuronAI\RAG\Schema\DocumentSchema;
use NeuronAI\RAG\VectorStore\Filter\Filter;
use NeuronAI\RAG\VectorStore\Filter\FilterGroup;
use NeuronAI\RAG\VectorStore\MemoryVectorStore;
use NeuronAI\Testing\FakeEmbeddingsProvider;
use NeuronAI\Tests\RAG\Stub\StaticRetrieval;
use PHPUnit\Framework\TestCase;

use function array_map;
use function sort;

class RetrievalNodeTest extends TestCase
{
    public function test_the_query_reaches_the_strategy_and_the_result_event(): void
    {
        $documents = [new Document('First'), new Document('Second')];
        $retrieval = new StaticRetrieval($documents);
        $query = new UserMessage('Question');

        $result = (new RetrievalNode($retrieval))(new QueryPreProcessedEvent($query), new AgentState());

        $this->assertSame($query, $retrieval->received[0]['query']);
        $this->assertSame($query, $result->query);
        $this->assertSame($documents, $result->documents);
    }

    public function test_duplicated_contents_are_retrieved_once_in_first_occurrence_order(): void
    {
        $retrieval = new StaticRetrieval([
            new Document('Paris'),
            new Document('Rome'),
            (new Document('Paris'))->setSourceName('copy.md'),
            new Document('Madrid'),
            new Document('Rome'),
        ]);

        $result = (new RetrievalNode($retrieval))(new QueryPreProcessedEvent(new UserMessage('Question')), new AgentState());

        $this->assertSame(
            ['Paris', 'Rome', 'Madrid'],
            array_map(static fn (Document $document): string => $document->getContent(), $result->documents),
        );
    }

    public function test_contents_differing_only_by_whitespace_or_case_are_distinct(): void
    {
        $retrieval = new StaticRetrieval([new Document('Paris'), new Document('paris'), new Document('Paris ')]);

        $result = (new RetrievalNode($retrieval))(new QueryPreProcessedEvent(new UserMessage('Question')), new AgentState());

        $this->assertSame(
            ['Paris', 'paris', 'Paris '],
            array_map(static fn (Document $document): string => $document->getContent(), $result->documents),
        );
    }

    public function test_no_scope_and_no_injected_filters_search_unfiltered(): void
    {
        $retrieval = new StaticRetrieval();

        (new RetrievalNode($retrieval))(new QueryPreProcessedEvent(new UserMessage('Question')), new AgentState());

        $this->assertNull($retrieval->received[0]['filters']);
    }

    public function test_the_scope_alone_is_forwarded(): void
    {
        $retrieval = new StaticRetrieval();
        $scope = Filter::eq('tenant', 'acme');

        (new RetrievalNode($retrieval, $scope))(new QueryPreProcessedEvent(new UserMessage('Question')), new AgentState());

        $this->assertSame($scope, $retrieval->received[0]['filters']);
    }

    public function test_injected_filters_alone_are_forwarded(): void
    {
        $retrieval = new StaticRetrieval();
        $injected = Filter::eq('lang', 'en');
        $event = (new QueryPreProcessedEvent(new UserMessage('Question')))->addFilters($injected);

        (new RetrievalNode($retrieval))($event, new AgentState());

        $this->assertSame($injected, $retrieval->received[0]['filters']);
    }

    public function test_scope_and_every_injected_filter_are_anded_at_the_root(): void
    {
        $retrieval = new StaticRetrieval();
        $event = (new QueryPreProcessedEvent(new UserMessage('Question')))
            ->addFilters(Filter::eq('lang', 'en'))
            ->addFilters(FilterGroup::anyOf(Filter::eq('year', 2024), Filter::eq('year', 2025)));

        (new RetrievalNode($retrieval, Filter::eq('tenant', 'acme')))($event, new AgentState());

        $this->assertSame([
            'operator' => 'and',
            'conditions' => [
                ['operator' => 'eq', 'field' => 'tenant', 'value' => 'acme'],
                ['operator' => 'eq', 'field' => 'lang', 'value' => 'en'],
                ['operator' => 'or', 'conditions' => [
                    ['operator' => 'eq', 'field' => 'year', 'value' => 2024],
                    ['operator' => 'eq', 'field' => 'year', 'value' => 2025],
                ]],
            ],
        ], $retrieval->received[0]['filters']?->toArray());
    }

    public function test_an_injected_or_filter_cannot_widen_the_tenant_scope(): void
    {
        $store = new MemoryVectorStore(topK: 10, schema: DocumentSchema::of(
            DocumentField::string('tenant')->required()->filterable(),
            DocumentField::string('lang')->filterable(),
        ));
        $embeddings = new FakeEmbeddingsProvider();
        foreach ([['acme', 'en'], ['acme', 'it'], ['globex', 'en'], ['globex', 'it']] as [$tenant, $lang]) {
            $store->addDocument($embeddings->embedDocument(
                (new Document("{$tenant}-{$lang}"))->addMetadata('tenant', $tenant)->addMetadata('lang', $lang),
            ));
        }
        $event = (new QueryPreProcessedEvent(new UserMessage('Question')))
            ->addFilters(FilterGroup::anyOf(Filter::eq('tenant', 'globex'), Filter::eq('lang', 'en')));

        $result = (new RetrievalNode(new SimilarityRetrieval($store, $embeddings), Filter::eq('tenant', 'acme')))($event, new AgentState());

        $contents = array_map(static fn (Document $document): string => $document->getContent(), $result->documents);
        sort($contents);
        $this->assertSame(['acme-en'], $contents);
    }
}
