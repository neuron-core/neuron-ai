<?php

declare(strict_types=1);

namespace NeuronAI\Tests\RAG;

use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\Events\QueryPreProcessedEvent;
use NeuronAI\RAG\Nodes\RetrievalNode;
use NeuronAI\RAG\RAG;
use NeuronAI\RAG\Schema\DocumentField;
use NeuronAI\RAG\Schema\DocumentSchema;
use NeuronAI\RAG\VectorStore\Filter\Filter;
use NeuronAI\RAG\VectorStore\Filter\FilterExpression;
use NeuronAI\RAG\VectorStore\Filter\FilterGroup;
use NeuronAI\RAG\VectorStore\MemoryVectorStore;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Testing\FakeEmbeddingsProvider;
use NeuronAI\Testing\FakeMiddleware;
use NeuronAI\Testing\FakeVectorStore;
use NeuronAI\Testing\RequestRecord;
use NeuronAI\Workflow\Events\Event;
use NeuronAI\Workflow\NodeInterface;
use NeuronAI\Workflow\WorkflowResources;
use NeuronAI\Workflow\WorkflowState;
use PHPUnit\Framework\TestCase;

/**
 * Retrieval filters reach the store through the scope hook and through
 * middleware on RetrievalNode; they only ever narrow the search.
 */
class RAGRetrievalFiltersTest extends TestCase
{
    protected function schema(): DocumentSchema
    {
        return DocumentSchema::of(
            DocumentField::string('tenant')->required()->filterable(),
            DocumentField::string('lang')->filterable(),
        );
    }

    protected function injecting(FilterExpression $filter, bool $onlyOnce = false): FakeMiddleware
    {
        $injected = false;

        return (new FakeMiddleware())->setBeforeHandler(
            static function (NodeInterface $node, Event $event, WorkflowState $state, WorkflowResources $resources) use ($filter, $onlyOnce, &$injected): void {
                if ($event instanceof QueryPreProcessedEvent && !($onlyOnce && $injected)) {
                    $event->addFilters($filter);
                    $injected = true;
                }
            },
        );
    }

    public function test_middleware_filters_are_anded_with_the_retrieval_scope(): void
    {
        $store = new FakeVectorStore(schema: $this->schema());
        $rag = RAG::make()->setEmbeddingsProvider(new FakeEmbeddingsProvider())->setVectorStore($store)
            ->setRetrievalScope(Filter::eq('tenant', 'acme'));
        $rag->setAiProvider(new FakeAIProvider(new AssistantMessage('Answer')));
        $rag->addMiddleware(RetrievalNode::class, $this->injecting(Filter::eq('lang', 'en')));

        $rag->chat(new UserMessage('Question'));

        $store->assertSearchedWithFilters(FilterGroup::and(Filter::eq('tenant', 'acme'), Filter::eq('lang', 'en')));
        $store->assertSearchCount(1);
    }

    public function test_injected_filters_do_not_leak_into_the_next_run(): void
    {
        $store = new FakeVectorStore(schema: $this->schema());
        $rag = RAG::make()->setEmbeddingsProvider(new FakeEmbeddingsProvider())->setVectorStore($store)
            ->setRetrievalScope(Filter::eq('tenant', 'acme'));
        $rag->setAiProvider(new FakeAIProvider(new AssistantMessage('First'), new AssistantMessage('Second')));
        $rag->addMiddleware(RetrievalNode::class, $this->injecting(Filter::eq('lang', 'en'), onlyOnce: true));

        $rag->chat(new UserMessage('First question'));
        $rag->chat(new UserMessage('Second question'));

        $searches = [];
        foreach ($store->getRecorded() as $record) {
            $searches[] = $record->request?->filters?->toArray();
        }
        $this->assertSame([
            FilterGroup::and(Filter::eq('tenant', 'acme'), Filter::eq('lang', 'en'))->toArray(),
            Filter::eq('tenant', 'acme')->toArray(),
        ], $searches);
    }

    public function test_an_injected_or_filter_cannot_expose_another_tenant(): void
    {
        $embeddings = new FakeEmbeddingsProvider();
        $store = new MemoryVectorStore(topK: 10, schema: $this->schema());
        $store->addDocuments($embeddings->embedDocuments([
            (new Document('Acme handbook'))->setMetadata(['tenant' => 'acme', 'lang' => 'en']),
            (new Document('Globex secrets'))->setMetadata(['tenant' => 'globex', 'lang' => 'en']),
        ]));
        $provider = new FakeAIProvider(new AssistantMessage('Answer'));
        $rag = RAG::make()->setEmbeddingsProvider($embeddings)->setVectorStore($store)->setRetrievalScope(Filter::eq('tenant', 'acme'));
        $rag->setAiProvider($provider);
        $rag->addMiddleware(RetrievalNode::class, $this->injecting(
            FilterGroup::anyOf(Filter::eq('tenant', 'globex'), Filter::eq('lang', 'en')),
        ));

        $rag->chat(new UserMessage('Show me everything'));

        $provider->assertSent(static fn (RequestRecord $record): bool => $record->systemPrompt?->contains('Acme handbook') === true);
        $provider->assertSent(static fn (RequestRecord $record): bool => $record->systemPrompt?->contains('Globex secrets') === false);
    }
}
