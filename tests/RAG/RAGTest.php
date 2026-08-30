<?php

declare(strict_types=1);

namespace NeuronAI\Tests\RAG;

use NeuronAI\Agent\Memory\SemanticMemory;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\RAG;
use NeuronAI\RAG\Schema\DocumentField;
use NeuronAI\RAG\Schema\DocumentSchema;
use NeuronAI\RAG\Schema\DocumentSchemaException;
use NeuronAI\RAG\VectorStore\Filter\Filter;
use NeuronAI\RAG\VectorStore\Filter\FilterExpression;
use NeuronAI\RAG\VectorStore\MemoryVectorStore;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Testing\FakeEmbeddingsProvider;
use NeuronAI\Testing\FakeVectorStore;
use NeuronAI\Testing\RequestRecord;
use PHPUnit\Framework\TestCase;

use function iterator_to_array;

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

        $stream = $rag->stream(new UserMessage('Capital of France?'));
        iterator_to_array($stream);

        $this->assertSame('Paris is the capital.', $stream->getReturn()->getMessage()->getContent());
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

    public function test_add_documents_validates_schema_before_embedding(): void
    {
        $embeddings = new FakeEmbeddingsProvider();
        $vectorStore = new FakeVectorStore(schema: DocumentSchema::of(
            DocumentField::string('tenant')->required(),
        ));

        $rag = RAG::make()
            ->setEmbeddingsProvider($embeddings)
            ->setVectorStore($vectorStore);

        try {
            $rag->addDocuments([new Document('Invalid document')]);
            $this->fail('A document missing required metadata should not be embedded.');
        } catch (DocumentSchemaException $exception) {
            $this->assertStringContainsString('tenant', $exception->getMessage());
        }

        $embeddings->assertNothingEmbedded();
        $vectorStore->assertNothingStored();
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

    public function test_retrieval_scope_hook_constrains_the_default_strategy(): void
    {
        $vectorStore = new FakeVectorStore(schema: DocumentSchema::of(
            DocumentField::string('tenant')->required()->filterable(),
        ));
        $rag = new class () extends RAG {
            protected function retrievalScope(): FilterExpression
            {
                return Filter::eq('tenant', 'acme');
            }
        };

        $rag->setAiProvider(new FakeAIProvider(new AssistantMessage('Answer')));
        $rag->setEmbeddingsProvider(new FakeEmbeddingsProvider());
        $rag->setVectorStore($vectorStore);
        $rag->chat(new UserMessage('Question'));

        $vectorStore->assertSearchedWithFilters(Filter::eq('tenant', 'acme'));
        $this->addToAssertionCount(1);
    }

    public function test_rag_recalls_and_stores_agent_memory_without_extra_wiring(): void
    {
        $provider = new FakeAIProvider(new AssistantMessage('Your preferred city is Paris.'));
        $memory = new SemanticMemory(
            new MemoryVectorStore(),
            new FakeEmbeddingsProvider(),
            ['thread-1'],
        );
        $memory->remember('thread-1', 'I prefer Paris.', 'I will remember that.');

        $rag = RAG::make(threadId: 'thread-1');
        $rag->setAiProvider($provider);
        $rag->setEmbeddingsProvider(new FakeEmbeddingsProvider());
        $rag->setVectorStore(new FakeVectorStore());
        $rag->setMemory($memory);

        $rag->chat(new UserMessage('Which city do I prefer?'));

        $provider->assertSent(
            fn (RequestRecord $record): bool => $record->systemPrompt?->contains('I prefer Paris.') ?? false
        );
        $this->assertCount(2, $memory->recall('Which city do I prefer?'));
    }
}
