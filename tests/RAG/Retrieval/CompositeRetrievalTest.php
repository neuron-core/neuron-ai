<?php

declare(strict_types=1);

namespace NeuronAI\Tests\RAG\Retrieval;

use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\RAG;
use NeuronAI\RAG\Retrieval\CompositeRetrieval;
use NeuronAI\RAG\Retrieval\SemanticMemoryRetrieval;
use NeuronAI\RAG\Retrieval\SimilarityRetrieval;
use NeuronAI\RAG\VectorStore\Filter\Filter;
use NeuronAI\RAG\VectorStore\MemoryVectorStore;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Testing\FakeEmbeddingsProvider;
use NeuronAI\Testing\FakeVectorStore;
use NeuronAI\Testing\RequestRecord;
use NeuronAI\Tests\RAG\Nodes\Stub\ConversationAgent;
use PHPUnit\Framework\TestCase;

use function substr_count;

class CompositeRetrievalTest extends TestCase
{
    public function test_children_preserve_order_and_receive_the_same_query_and_mandatory_filters(): void
    {
        $first = new Document('First');
        $second = new Document('Second');
        $stores = [new FakeVectorStore([$first]), new FakeVectorStore([$second])];
        $embeddings = new FakeEmbeddingsProvider();
        $retrieval = new CompositeRetrieval([
            new SimilarityRetrieval($stores[0], $embeddings),
            new CompositeRetrieval([new SimilarityRetrieval($stores[1], $embeddings)]),
        ]);
        $filters = Filter::eq('sourceName', 'allowed');

        $this->assertSame([$first, $second], $retrieval->retrieve(new UserMessage('Question'), $filters));
        foreach ($stores as $store) {
            $store->assertSearchedWithFilters($filters);
            $store->assertSearchCount(1);
        }
        $this->assertSame(['Question', 'Question'], $embeddings->getRecorded());
    }

    public function test_rag_combines_memory_and_knowledge_without_automatically_creating_memories(): void
    {
        $embeddings = new FakeEmbeddingsProvider();
        $memoryStore = new MemoryVectorStore();
        $writer = ConversationAgent::make(workflowId: 'past-thread');
        $writer->conversationStore = $memoryStore;
        $writer->conversationEmbeddings = $embeddings;
        $writer->setAiProvider(new FakeAIProvider(new AssistantMessage('Understood.')));
        $writer->chat(new UserMessage('I prefer Paris.'));
        $knowledgeStore = new MemoryVectorStore();
        $knowledgeStore->addDocument($embeddings->embedDocument(new Document('Paris is in France.')));
        $memoryRetrieval = new SemanticMemoryRetrieval($memoryStore, $embeddings, ['past-thread']);
        $provider = new FakeAIProvider(new AssistantMessage('Paris, France.'));
        $rag = RAG::make(workflowId: 'current-thread');
        $rag->setAiProvider($provider);
        $rag->setRetrieval(new CompositeRetrieval([
            $memoryRetrieval,
            new SimilarityRetrieval($knowledgeStore, $embeddings),
        ]));

        $rag->chat(new UserMessage('Where is my preferred city?'));

        $provider->assertSent(static fn (RequestRecord $record): bool =>
            $record->systemPrompt->contains('I prefer Paris.')
            && $record->systemPrompt->contains('Paris is in France.'));
        $this->assertCount(1, $memoryRetrieval->retrieve(new UserMessage('Paris')));
        $this->assertCount(2, $rag->getChatHistory()->getMessages());
        $this->assertSame('Where is my preferred city?', $rag->getChatHistory()->getMessages()[0]->getContent());
    }

    public function test_rag_deduplicates_results_from_multiple_retrievers(): void
    {
        $store = new FakeVectorStore([new Document('Shared context')]);
        $child = new SimilarityRetrieval($store, new FakeEmbeddingsProvider());
        $provider = new FakeAIProvider(new AssistantMessage('Answer'));
        $rag = RAG::make();
        $rag->setAiProvider($provider);
        $rag->setRetrieval(new CompositeRetrieval([$child, $child]));
        $rag->chat(new UserMessage('Question'));

        $this->assertSame(1, substr_count($provider->getRecorded()[0]->systemPrompt->getContent(), 'Shared context'));
    }
}
