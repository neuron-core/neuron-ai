<?php

declare(strict_types=1);

namespace NeuronAI\Tests\RAG;

use NeuronAI\Chat\History\InMemoryMessageStore;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Exceptions\ProviderException;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\RAG;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Testing\FakeEmbeddingsProvider;
use NeuronAI\Testing\FakeVectorStore;
use NeuronAI\Workflow\Persistence\InMemoryPersistence;
use NeuronAI\Tests\RAG\Stub\ItalianPreProcessNode;
use NeuronAI\Tests\RAG\Stub\ItalianRAG;
use NeuronAI\Tests\Support\ReadsTurnContext;
use PHPUnit\Framework\TestCase;

/**
 * RAG's entry chain commits its retrieval steps before inference, so a
 * provider failure leaves a failed generation whose records hold the first
 * question's retrieval. The next turn supersedes it: retrieval runs again for
 * the new question and the provider sees only that question.
 */
class RAGDurableTurnTest extends TestCase
{
    use ReadsTurnContext;

    /**
     * @param class-string<RAG> $class
     */
    protected function makeRag(
        FakeAIProvider $provider,
        FakeVectorStore $vectorStore,
        InMemoryMessageStore $messageStore,
        InMemoryPersistence $persistence,
        string $class = RAG::class,
    ): RAG {
        $rag = $class::make(workflowId: 'thread');
        $rag->setAiProvider($provider);
        $rag->setEmbeddingsProvider(new FakeEmbeddingsProvider());
        $rag->setVectorStore($vectorStore);
        $rag->setMessageStore($messageStore);
        $rag->setPersistence($persistence);

        return $rag;
    }

    public function test_a_failed_turn_is_superseded_and_retrieval_runs_again_for_the_new_question(): void
    {
        $provider = new FakeAIProvider();
        $vectorStore = new FakeVectorStore([
            new Document('France is a country in Europe. Its capital is Paris.'),
        ]);
        $messageStore = new InMemoryMessageStore();
        $persistence = new InMemoryPersistence();

        try {
            $this->makeRag($provider, $vectorStore, $messageStore, $persistence)
                ->chat(new UserMessage('What is the capital of France?'));
            $this->fail('Expected the provider failure to propagate.');
        } catch (ProviderException) {
        }

        // Retrieval committed before inference failed; the thread holds a
        // failed generation and the history tail is clean.
        $vectorStore->assertSearchCount(1);
        $threadId = 'thread';
        $this->assertNotNull($persistence->get($threadId, '__control'));
        $this->assertCount(0, $messageStore->loadActive('thread'));

        $provider->addResponses(new AssistantMessage('Paris.'));
        $state = $this->makeRag($provider, $vectorStore, $messageStore, $persistence)
            ->chat(new UserMessage('Which city is the French capital?'));

        $this->assertSame('Paris.', $state->getMessage()->getContent());

        // A fresh generation: retrieval ran for the new question, and the
        // only provider request carries that question, not the failed one.
        $vectorStore->assertSearchCount(2);
        $this->assertSame(1, $provider->getCallCount());
        $sent = $provider->getRecorded()[0]->messages;
        $this->assertCount(1, $sent);
        $this->assertSame('Which city is the French capital?', $this->blocks($sent[0])[0]);
        $this->assertStringNotContainsString('What is the capital of France?', $this->turnContext($provider->getRecorded()[0]));

        $this->assertCount(2, $messageStore->loadActive('thread'));
        $this->assertNull($persistence->get($threadId, '__control'));
    }

    public function test_a_new_turn_closes_the_question_a_failed_turn_left_open(): void
    {
        $provider = new FakeAIProvider(new AssistantMessage('Paris.'));
        $vectorStore = new FakeVectorStore([
            new Document('France is a country in Europe. Its capital is Paris.'),
        ]);
        $messageStore = new InMemoryMessageStore();
        // What a turn that failed after its first inference leaves behind.
        $messageStore->append('thread', new UserMessage('What is the capital of France?'));

        $this->makeRag($provider, $vectorStore, $messageStore, new InMemoryPersistence())
            ->chat(new UserMessage('Which city is the French capital?'));

        $sent = $provider->getRecorded()[0]->messages;
        $this->assertCount(3, $sent);
        $this->assertSame('What is the capital of France?', $this->blocks($sent[0])[0]);
        $this->assertInstanceOf(AssistantMessage::class, $sent[1]);
        $this->assertSame('Which city is the French capital?', $this->blocks($sent[2])[0]);
        $this->assertCount(4, $messageStore->loadActive('thread'));
    }

    public function test_an_entry_node_subclass_changes_what_the_closing_says(): void
    {
        $provider = new FakeAIProvider(new AssistantMessage('Parigi.'));
        $vectorStore = new FakeVectorStore([
            new Document('France is a country in Europe. Its capital is Paris.'),
        ]);
        $messageStore = new InMemoryMessageStore();
        $messageStore->append('thread', new UserMessage('Qual è la capitale della Francia?'));

        $this->makeRag($provider, $vectorStore, $messageStore, new InMemoryPersistence(), ItalianRAG::class)
            ->chat(new UserMessage('Quale città è la capitale francese?'));

        $sent = $provider->getRecorded()[0]->messages;
        $this->assertCount(3, $sent);
        $this->assertSame(ItalianPreProcessNode::INTERRUPTED_ANSWER, $sent[1]->getContent());
    }
}
