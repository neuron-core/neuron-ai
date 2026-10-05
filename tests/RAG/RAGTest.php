<?php

declare(strict_types=1);

namespace NeuronAI\Tests\RAG;

use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\RAG;
use NeuronAI\RAG\Retrieval\RetrievalInterface;
use NeuronAI\RAG\Schema\DocumentField;
use NeuronAI\RAG\Schema\DocumentSchema;
use NeuronAI\RAG\Schema\DocumentSchemaException;
use NeuronAI\RAG\VectorStore\Filter\Filter;
use NeuronAI\RAG\VectorStore\Filter\FilterExpression;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Testing\FakeEmbeddingsProvider;
use NeuronAI\Testing\FakeVectorStore;
use NeuronAI\Tests\RAG\Stub\SuffixPreProcessor;
use NeuronAI\Tests\Support\ReadsTurnContext;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function iterator_to_array;
use function substr_count;

class RAGTest extends TestCase
{
    use ReadsTurnContext;

    public function test_chat_with_retrieved_documents(): void
    {
        $provider = new FakeAIProvider(
            new AssistantMessage('Paris is the capital of France.')
        );

        $vectorStore = new FakeVectorStore([
            (new Document('France is a country in Europe. Its capital is Paris.'))->setSourceType('file')->setSourceName('europe.md'),
        ]);

        $rag = RAG::make()->setThreadId('thread_1');
        $rag->setAiProvider($provider)->setInstructions('You answer geography questions.');
        $rag->setEmbeddingsProvider(new FakeEmbeddingsProvider());
        $rag->setVectorStore($vectorStore);

        $message = $rag->chat(new UserMessage('What is the capital of France?'))->getMessage();

        $this->assertSame('Paris is the capital of France.', $message->getContent());
        $provider->assertCallCount(1);
        $vectorStore->assertSearchCount(1);
        $request = $provider->getRecorded()[0];
        $this->assertSame('You answer geography questions.', $request->systemPrompt?->getContent());
        $this->assertCount(1, $request->messages);
        // The documents travel with the question, after its own content
        $this->assertSame([
            'What is the capital of France?',
            "<EXTRA-CONTEXT>"
            ."Source Type: file\nSource Name: europe.md\nContent: France is a country in Europe. Its capital is Paris.\n\n"
            ."</EXTRA-CONTEXT>",
        ], $this->blocks($request->messages[0]));
        $this->assertSame(['What is the capital of France?'], $this->blocks($rag->getChatHistory()->getMessages()[0]));
    }

    public function test_retrieved_context_does_not_accumulate_across_turns(): void
    {
        $provider = new FakeAIProvider(new AssistantMessage('First reply'), new AssistantMessage('Second reply'));
        $vectorStore = new FakeVectorStore([new Document('First context')]);
        $rag = RAG::make()->setThreadId('thread_1');
        $rag->setAiProvider($provider)->setInstructions('Base instructions');
        $rag->setEmbeddingsProvider(new FakeEmbeddingsProvider())->setVectorStore($vectorStore);

        $rag->chat(new UserMessage('First question'));
        $vectorStore->setSearchResults([new Document('Second context')]);
        $rag->chat(new UserMessage('Second question'));

        $request = $provider->getRecorded()[1];
        $this->assertSame('Base instructions', $request->systemPrompt?->getContent());
        // The first question comes back as it was stored, without the documents of its turn
        $this->assertSame(['First question'], $this->blocks($request->messages[0]));
        [$question, $context] = $this->blocks($request->messages[2]);
        $this->assertSame('Second question', $question);
        $this->assertSame(1, substr_count($context, '<EXTRA-CONTEXT>'));
        $this->assertStringContainsString('Second context', $context);
        $this->assertStringNotContainsString('First context', $context);
    }

    public function test_a_rewritten_query_drives_retrieval_while_the_model_answers_the_original_question(): void
    {
        $provider = new FakeAIProvider(new AssistantMessage('Answer'));
        $embeddings = new FakeEmbeddingsProvider();
        $rag = RAG::make()
            ->setThreadId('thread_1')
            ->setEmbeddingsProvider($embeddings)
            ->setVectorStore(new FakeVectorStore([new Document('Context')]))
            ->setPreProcessors([new SuffixPreProcessor(' with synonyms')]);
        $rag->setAiProvider($provider);

        $rag->chat(new UserMessage('Original question'));

        $this->assertSame(['Original question with synonyms'], $embeddings->getRecorded());
        [$question, $context] = $this->blocks($provider->getRecorded()[0]->messages[0]);
        $this->assertSame('Original question', $question);
        $this->assertStringNotContainsString('with synonyms', $context);
        $this->assertSame('Original question', $rag->getChatHistory()->getMessages()[0]->getContent());
    }

    public function test_a_retrieval_failure_stops_the_turn_before_inference_and_history(): void
    {
        $provider = new FakeAIProvider(new AssistantMessage('Never sent'));
        $retrieval = $this->createMock(RetrievalInterface::class);
        $retrieval->method('retrieve')->willThrowException(new RuntimeException('Vector store unavailable.'));
        $rag = RAG::make()->setThreadId('thread_1')->setRetrieval($retrieval);
        $rag->setAiProvider($provider);

        try {
            $rag->chat(new UserMessage('Question'));
            $this->fail('A retrieval failure must fail the turn.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Vector store unavailable.', $exception->getMessage());
        }

        $provider->assertNothingSent();
        $this->assertSame([], $rag->getChatHistory()->getMessages());
    }

    public function test_configuration_changes_refresh_the_entry_chain_on_the_next_turn(): void
    {
        $first = new FakeAIProvider(new AssistantMessage('First reply'));
        $second = new FakeAIProvider(new AssistantMessage('Second reply'));
        $vectorStore = new FakeVectorStore([new Document('Reference context')]);
        $rag = RAG::make()->setThreadId('thread_1');
        $rag->setAiProvider($first)->setInstructions('Original instructions');
        $rag->setEmbeddingsProvider(new FakeEmbeddingsProvider())->setVectorStore($vectorStore);
        $rag->chat(new UserMessage('First question'));

        $rag->setAiProvider($second)->setInstructions('Updated instructions');
        $rag->chat(new UserMessage('Second question'));

        $first->assertCallCount(1);
        $second->assertCallCount(1);
        $request = $second->getRecorded()[0];
        $this->assertSame('Updated instructions', $request->systemPrompt->getContent());
        $this->assertStringContainsString('Reference context', $this->blocks($request->messages[2])[1]);
        $vectorStore->assertSearchCount(2);
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

        $rag = RAG::make()->setThreadId('thread_1');
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

    public function test_copies_retrieve_what_the_definition_ingested(): void
    {
        $provider = new FakeAIProvider(new AssistantMessage('Paris.'));
        $definition = RAG::make()->setAiProvider($provider)->setEmbeddingsProvider(new FakeEmbeddingsProvider());

        // Ingestion resolves the default in-memory store on the definition, so every copy shares it.
        $definition->addDocuments([new Document('France is a country in Europe. Its capital is Paris.')]);
        $definition->for('thread_1')->chat(new UserMessage('What is the capital of France?'));

        $this->assertStringContainsString('Its capital is Paris.', $this->blocks($provider->getRecorded()[0]->messages[0])[1]);
        $this->assertNull($definition->getThreadId());
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

        $rag = RAG::make()->setThreadId('thread_1');
        $rag->setAiProvider($provider)->setInstructions('Base instructions');
        $rag->setEmbeddingsProvider(new FakeEmbeddingsProvider());
        $rag->setVectorStore($vectorStore);

        $message = $rag->chat(new UserMessage('Tell me about quantum physics'))->getMessage();

        $this->assertSame('I don\'t have enough information.', $message->getContent());
        $vectorStore->assertSearchCount(1);
        $request = $provider->getRecorded()[0];
        $this->assertSame('Base instructions', $request->systemPrompt?->getContent());
        // Nothing retrieved, nothing added: the question is sent as it is
        $this->assertSame(['Tell me about quantum physics'], $this->blocks($request->messages[0]));
        $this->assertCount(2, $rag->getChatHistory()->getMessages());
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

        $rag->setThreadId('thread_1');
        $rag->setAiProvider(new FakeAIProvider(new AssistantMessage('Answer')));
        $rag->setEmbeddingsProvider(new FakeEmbeddingsProvider());
        $rag->setVectorStore($vectorStore);
        $rag->chat(new UserMessage('Question'));

        $vectorStore->assertSearchedWithFilters(Filter::eq('tenant', 'acme'));
    }
}
