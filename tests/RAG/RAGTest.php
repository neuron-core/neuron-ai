<?php

declare(strict_types=1);

namespace NeuronAI\Tests\RAG;

use NeuronAI\Chat\Messages\Stream\AssistantMessage;
use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\RAG;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Testing\FakeEmbeddingsProvider;
use NeuronAI\Testing\FakeVectorStore;
use PHPUnit\Framework\TestCase;

class RAGTest extends TestCase
{
    public function test_chat_with_retrieved_documents(): void
    {
        $provider = new FakeAIProvider(
            new AssistantMessage('Paris is the capital of France.')
        );

        $vectorStore = new FakeVectorStore([
            new Document('France is a country in Europe. Its capital is Paris.'),
        ]);

        $rag = RAG::make();
        $rag->setAiProvider($provider);
        $rag->setEmbeddingsProvider(new FakeEmbeddingsProvider());
        $rag->setVectorStore($vectorStore);

        $message = $rag->chat(new UserMessage('What is the capital of France?'))->getMessage();

        $this->assertSame('Paris is the capital of France.', $message->getContent());
        $provider->assertCallCount(1);
        $vectorStore->assertSearchCount(1);
    }

    public function test_stream_with_retrieved_documents(): void
    {
        $provider = new FakeAIProvider(
            new AssistantMessage('Paris is the capital.')
        );
        $provider->setStreamChunkSize(5);

        $vectorStore = new FakeVectorStore([
            new Document('France capital is Paris.'),
        ]);

        $rag = RAG::make();
        $rag->setAiProvider($provider);
        $rag->setEmbeddingsProvider(new FakeEmbeddingsProvider());
        $rag->setVectorStore($vectorStore);

        $handler = $rag->stream(new UserMessage('Capital of France?'));

        $chunks = [];
        foreach ($handler->events() as $event) {
            if ($event instanceof TextChunk) {
                $chunks[] = $event->content;
            }
        }

        $this->assertNotEmpty($chunks);
        $vectorStore->assertSearchCount(1);
    }

    public function test_add_documents_embeds_and_stores(): void
    {
        $embeddings = new FakeEmbeddingsProvider();
        $vectorStore = new FakeVectorStore();

        $rag = RAG::make();
        $rag->setAiProvider(new FakeAIProvider());
        $rag->setEmbeddingsProvider($embeddings);
        $rag->setVectorStore($vectorStore);

        $rag->addDocuments([
            new Document('First document'),
            new Document('Second document'),
        ]);

        $embeddings->assertCallCount(2);
        $vectorStore->assertDocumentCount(2);
        $vectorStore->assertHasDocumentWithContent('First document');
        $vectorStore->assertHasDocumentWithContent('Second document');
    }

    public function test_no_documents_retrieved(): void
    {
        $provider = new FakeAIProvider(
            new AssistantMessage('I don\'t have enough information.')
        );

        $vectorStore = new FakeVectorStore([]);

        $rag = RAG::make();
        $rag->setAiProvider($provider);
        $rag->setEmbeddingsProvider(new FakeEmbeddingsProvider());
        $rag->setVectorStore($vectorStore);

        $message = $rag->chat(new UserMessage('Tell me about quantum physics'))->getMessage();

        $this->assertSame('I don\'t have enough information.', $message->getContent());
        $vectorStore->assertSearchCount(1);
    }
}
