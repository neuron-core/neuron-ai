<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Agent\Memory;

use NeuronAI\Agent\Memory\SemanticMemory;
use NeuronAI\Exceptions\AgentException;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\VectorStore\MemoryVectorStore;
use NeuronAI\RAG\VectorStore\SearchRequest;
use NeuronAI\Testing\FakeEmbeddingsProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class SemanticMemoryTest extends TestCase
{
    public function test_remember_recall_and_forget_are_scoped_by_thread_without_a_custom_schema(): void
    {
        $store = new MemoryVectorStore();
        $embeddings = new FakeEmbeddingsProvider();
        $threadOneMemory = new SemanticMemory($store, $embeddings, ['thread-1']);
        $threadTwoMemory = new SemanticMemory($store, $embeddings, ['thread-2']);

        $threadOneMemory->remember('thread-1', 'My favorite food is pizza.', 'I will remember that.');
        $threadOneMemory->remember('thread-2', 'My favorite food is sushi.', 'I will remember that.');

        $this->assertSame(
            ["User: My favorite food is pizza.\nAssistant: I will remember that."],
            $threadOneMemory->recall('What food do I like?'),
        );
        $this->assertSame(
            ["User: My favorite food is sushi.\nAssistant: I will remember that."],
            $threadTwoMemory->recall('What food do I like?'),
        );

        $threadOneMemory->forget('thread-1');

        $this->assertSame([], $threadOneMemory->recall('What food do I like?'));
        $this->assertCount(1, $threadTwoMemory->recall('What food do I like?'));
    }

    public function test_recall_thread_ids_cannot_be_empty(): void
    {
        $this->expectException(AgentException::class);
        $this->expectExceptionMessage('at least one thread ID');

        new SemanticMemory(
            new MemoryVectorStore(),
            new FakeEmbeddingsProvider(),
            [],
        );
    }

    public function test_recall_thread_ids_must_not_contain_empty_strings(): void
    {
        $this->expectException(AgentException::class);
        $this->expectExceptionMessage('non-empty strings');

        new SemanticMemory(
            new MemoryVectorStore(),
            new FakeEmbeddingsProvider(),
            ['thread-1', ''],
        );
    }

    public function test_recall_thread_ids_must_be_strings(): void
    {
        $this->expectException(AgentException::class);
        $this->expectExceptionMessage('non-empty strings');

        $reflection = new ReflectionClass(SemanticMemory::class);
        $constructor = $reflection->getConstructor();
        $this->assertNotNull($constructor);

        $constructor->invoke(
            $reflection->newInstanceWithoutConstructor(),
            new MemoryVectorStore(),
            new FakeEmbeddingsProvider(),
            ['thread-1', 42],
        );
    }

    public function test_top_k_must_be_positive(): void
    {
        $this->expectException(AgentException::class);
        $this->expectExceptionMessage('topK must be greater than zero');

        new SemanticMemory(
            new MemoryVectorStore(),
            new FakeEmbeddingsProvider(),
            ['thread-1'],
            topK: 0,
        );
    }

    public function test_top_k_limits_recall_globally_across_threads(): void
    {
        $memory = new SemanticMemory(
            new MemoryVectorStore(),
            new FakeEmbeddingsProvider(),
            ['thread-1', 'thread-2'],
            topK: 2,
        );

        $memory->remember('thread-1', 'First memory.', 'First answer.');
        $memory->remember('thread-1', 'Second memory.', 'Second answer.');
        $memory->remember('thread-2', 'Third memory.', 'Third answer.');
        $memory->remember('thread-2', 'Fourth memory.', 'Fourth answer.');

        $this->assertCount(2, $memory->recall('Recall relevant memories.'));
    }

    public function test_recall_and_forget_isolate_memory_documents_by_source_type(): void
    {
        $store = new MemoryVectorStore();
        $embeddings = new FakeEmbeddingsProvider();
        $memory = new SemanticMemory($store, $embeddings, ['thread-1']);
        $unrelatedDocument = (new Document('Unrelated RAG document.'))
            ->setSourceType('rag-document')
            ->setSourceName('thread-1');

        $store->addDocument($embeddings->embedDocument($unrelatedDocument));
        $memory->remember('thread-1', 'Remember me.', 'Stored in agent memory.');

        $this->assertSame(
            ["User: Remember me.\nAssistant: Stored in agent memory."],
            $memory->recall('What should you remember?'),
        );

        $memory->forget('thread-1');

        $remaining = $store->search(new SearchRequest(
            $embeddings->embedText('Unrelated RAG document.'),
            topK: 10,
        ));
        $this->assertCount(1, $remaining);
        $this->assertSame('Unrelated RAG document.', $remaining[0]->getContent());
    }

    public function test_recall_searches_only_the_explicit_thread_ids(): void
    {
        $store = new MemoryVectorStore();
        $embeddings = new FakeEmbeddingsProvider();
        $memory = new SemanticMemory($store, $embeddings, ['thread-1', 'thread-2', 'thread-1']);

        $memory->remember('thread-1', 'My project is called Neuron.', 'I will remember that.');
        $memory->remember('thread-2', 'I use PHP.', 'I will remember that.');
        $memory->remember('thread-3', 'I use another framework.', 'I will remember that.');

        $memories = $memory->recall('What do you know about me?');

        $this->assertCount(2, $memories);
        $this->assertStringContainsString('Neuron', $memories[0]);
        $this->assertStringContainsString('PHP', $memories[1]);

        $memory->forget('thread-1');

        $this->assertSame(
            ["User: I use PHP.\nAssistant: I will remember that."],
            $memory->recall('What do you know about me?'),
        );
    }
}
