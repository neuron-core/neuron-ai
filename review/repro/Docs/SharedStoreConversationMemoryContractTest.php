<?php

declare(strict_types=1);

namespace NeuronAI\Tests\RAG;

use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\Nodes\ConversationIngestionNode;
use NeuronAI\RAG\RAG;
use NeuronAI\RAG\VectorStore\MemoryVectorStore;
use NeuronAI\RAG\VectorStore\VectorStoreInterface;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Testing\FakeEmbeddingsProvider;
use PHPUnit\Framework\TestCase;

/**
 * skills/neuron-agent/references/conversation-memory.md documents a
 * RememberingAssistant that ingests conversations into "the configured RAG
 * store" and keeps the default retrieval. Recall is supposed to be limited to
 * an explicit, application-authorized thread allowlist.
 */
class SharedStoreConversationMemoryContractTest extends TestCase
{
    protected function rememberingAssistant(string $threadId, VectorStoreInterface $store, FakeAIProvider $provider): RAG
    {
        $assistant = new class ($threadId) extends RAG {
            protected function exitNodes(): array
            {
                return [new ConversationIngestionNode(
                    vectorStore: $this->resolveVectorStore(),
                    embeddingProvider: $this->resolveEmbeddingsProvider(),
                )];
            }
        };

        return $assistant
            ->setAiProvider($provider)
            ->setEmbeddingsProvider(new FakeEmbeddingsProvider())
            ->setVectorStore($store);
    }

    public function test_the_documented_shared_store_setup_never_recalls_another_threads_conversation(): void
    {
        $store = new MemoryVectorStore(topK: 4);
        $store->addDocument((new FakeEmbeddingsProvider())->embedDocument(
            (new Document('Lockers are on the second floor.'))->setSourceType('manual')->setSourceName('office.md'),
        ));

        $this->rememberingAssistant('alice-thread', $store, new FakeAIProvider(new AssistantMessage('Noted, your code is 4417.')))
            ->chat(new UserMessage('My locker code is 4417.'));

        $bobProvider = new FakeAIProvider(new AssistantMessage('On the second floor.'));
        $this->rememberingAssistant('bob-thread', $store, $bobProvider)
            ->chat(new UserMessage('Where are the lockers?'));

        $bobContext = (string) $bobProvider->getRecorded()[0]->systemPrompt?->getContent();
        $this->assertStringContainsString('Lockers are on the second floor.', $bobContext);
        $this->assertStringNotContainsString('4417', $bobContext, "Alice's conversation is recalled in Bob's thread.");
    }
}
