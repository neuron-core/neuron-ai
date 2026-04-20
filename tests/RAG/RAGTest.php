<?php

declare(strict_types=1);

namespace NeuronAI\Tests\RAG;

use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\RAG\ContextInjector\ContextInjectorInterface;
use NeuronAI\RAG\ContextInjector\SystemPromptInjector;
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

    public function test_document_separator_newlines(): void
    {
        $provider = new FakeAIProvider(new AssistantMessage('Answer.'));

        $vectorStore = new FakeVectorStore([
            new Document('First document content.'),
            new Document('Second document content.'),
        ]);

        $rag = RAG::make();
        $rag->setAiProvider($provider);
        $rag->setEmbeddingsProvider(new FakeEmbeddingsProvider());
        $rag->setVectorStore($vectorStore);
        $rag->setContextInjector(new SystemPromptInjector("\n\n"));

        $rag->chat(new UserMessage('Question?'))->getMessage();

        $provider->assertSent(function ($record): bool {
            $prompt = $record->systemPrompt ?? '';
            // Both documents must appear, separated by a blank line
            return str_contains($prompt, "First document content.")
                && str_contains($prompt, "Second document content.")
                && str_contains($prompt, "\n\n");
        });
    }

    public function test_custom_document_separator(): void
    {
        $provider = new FakeAIProvider(new AssistantMessage('Answer.'));

        $vectorStore = new FakeVectorStore([
            new Document('Doc A.'),
            new Document('Doc B.'),
        ]);

        $rag = RAG::make();
        $rag->setAiProvider($provider);
        $rag->setEmbeddingsProvider(new FakeEmbeddingsProvider());
        $rag->setVectorStore($vectorStore);
        $rag->setContextInjector(new SystemPromptInjector("---\n"));

        $rag->chat(new UserMessage('Question?'))->getMessage();

        $provider->assertSent(function ($record): bool {
            return str_contains($record->systemPrompt ?? '', "---\n");
        });
    }

    public function test_custom_context_injector_is_called(): void
    {
        $provider = new FakeAIProvider(new AssistantMessage('Answer.'));

        $vectorStore = new FakeVectorStore([
            new Document('Some content.'),
        ]);

        $injector = new class implements ContextInjectorInterface {
            public bool $called = false;

            /** @param \NeuronAI\RAG\Document[] $documents */
            public function inject(array $documents, string $instructions, \NeuronAI\Agent\AgentState $state): string
            {
                $this->called = true;
                $context = implode('', array_map(fn ($d) => $d->getContent(), $documents));
                return $instructions . '[CUSTOM]' . $context . '[/CUSTOM]';
            }
        };

        $rag = RAG::make();
        $rag->setAiProvider($provider);
        $rag->setEmbeddingsProvider(new FakeEmbeddingsProvider());
        $rag->setVectorStore($vectorStore);
        $rag->setContextInjector($injector);

        $rag->chat(new UserMessage('Question?'))->getMessage();

        $this->assertTrue($injector->called, 'Custom context injector was not called.');

        $provider->assertSent(function ($record): bool {
            return str_contains($record->systemPrompt ?? '', '[CUSTOM]')
                && str_contains($record->systemPrompt ?? '', '[/CUSTOM]');
        });
    }

    public function test_default_injector_backwards_compat(): void
    {
        $provider = new FakeAIProvider(new AssistantMessage('Paris.'));

        $vectorStore = new FakeVectorStore([
            new Document('France capital is Paris.'),
        ]);

        $rag = RAG::make();
        $rag->setAiProvider($provider);
        $rag->setEmbeddingsProvider(new FakeEmbeddingsProvider());
        $rag->setVectorStore($vectorStore);

        $rag->chat(new UserMessage('Capital of France?'))->getMessage();

        $this->assertGreaterThan(0, $provider->getCallCount(), 'Provider was never called. Records: ' . $provider->getCallCount());

        $provider->assertSent(function ($record): bool {
            $prompt = $record->systemPrompt ?? '';
            return str_contains($prompt, '<EXTRA-CONTEXT>')
                && str_contains($prompt, '</EXTRA-CONTEXT>')
                && str_contains($prompt, 'France capital is Paris.');
        });
    }
}
