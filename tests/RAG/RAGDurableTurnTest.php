<?php

declare(strict_types=1);

namespace NeuronAI\Tests\RAG;

use NeuronAI\Chat\History\InMemoryChatHistory;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Exceptions\ProviderException;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\RAG;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Testing\FakeEmbeddingsProvider;
use NeuronAI\Testing\FakeVectorStore;
use NeuronAI\Workflow\Persistence\InMemoryPersistence;
use PHPUnit\Framework\TestCase;

use function str_contains;

/**
 * RAG's entry chain commits its retrieval steps before inference, so a
 * provider failure leaves a failed generation whose records hold the first
 * question's retrieval. The next turn supersedes it: retrieval runs again for
 * the new question and the provider sees only that question.
 */
class RAGDurableTurnTest extends TestCase
{
    protected function makeRag(
        FakeAIProvider $provider,
        FakeVectorStore $vectorStore,
        InMemoryChatHistory $history,
        InMemoryPersistence $persistence,
    ): RAG {
        $rag = RAG::make();
        $rag->setAiProvider($provider);
        $rag->setEmbeddingsProvider(new FakeEmbeddingsProvider());
        $rag->setVectorStore($vectorStore);
        $rag->setChatHistory($history);
        $rag->setPersistence($persistence);

        return $rag;
    }

    public function test_a_failed_turn_is_superseded_and_retrieval_runs_again_for_the_new_question(): void
    {
        $provider = new FakeAIProvider();
        $vectorStore = new FakeVectorStore([
            new Document('France is a country in Europe. Its capital is Paris.'),
        ]);
        $history = new InMemoryChatHistory();
        $persistence = new InMemoryPersistence();

        try {
            $this->makeRag($provider, $vectorStore, $history, $persistence)
                ->chat(new UserMessage('What is the capital of France?'));
            $this->fail('Expected the provider failure to propagate.');
        } catch (ProviderException) {
        }

        // Retrieval committed before inference failed; the thread holds a
        // failed generation and the history tail is clean.
        $vectorStore->assertSearchCount(1);
        $threadId = (string) $history->getThreadId();
        $this->assertNotNull($persistence->get($threadId, '__control'));
        $this->assertCount(0, $history->getMessages());

        $provider->addResponses(new AssistantMessage('Paris.'));
        $state = $this->makeRag($provider, $vectorStore, $history, $persistence)
            ->chat(new UserMessage('Which city is the French capital?'));

        $this->assertSame('Paris.', $state->getMessage()->getContent());

        // A fresh generation: retrieval ran for the new question, and the
        // only provider request carries that question, not the failed one.
        $vectorStore->assertSearchCount(2);
        $this->assertSame(1, $provider->getCallCount());
        $sent = $provider->getRecorded()[0]->messages;
        $this->assertCount(1, $sent);
        $this->assertTrue(str_contains((string) $sent[0]->getContent(), 'Which city is the French capital?'));
        $this->assertFalse(str_contains((string) $sent[0]->getContent(), 'What is the capital of France?'));

        $this->assertCount(2, $history->getMessages());
        $this->assertNull($persistence->get($threadId, '__control'));
    }
}
