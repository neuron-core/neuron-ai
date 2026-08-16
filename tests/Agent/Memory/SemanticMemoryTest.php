<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Agent\Memory;

use NeuronAI\Agent\Memory\SemanticMemory;
use NeuronAI\Exceptions\AgentException;
use NeuronAI\RAG\VectorStore\MemoryVectorStore;
use NeuronAI\Testing\FakeEmbeddingsProvider;
use PHPUnit\Framework\TestCase;

class SemanticMemoryTest extends TestCase
{
    public function test_remember_recall_and_forget_are_scoped_by_thread_without_a_custom_schema(): void
    {
        $memory = new SemanticMemory(
            new MemoryVectorStore(),
            new FakeEmbeddingsProvider(),
        );

        $memory->remember('thread-1', 'My favorite food is pizza.', 'I will remember that.');
        $memory->remember('thread-2', 'My favorite food is sushi.', 'I will remember that.');

        $this->assertSame(
            ["User: My favorite food is pizza.\nAssistant: I will remember that."],
            $memory->recall(['thread-1'], 'What food do I like?'),
        );
        $this->assertSame(
            ["User: My favorite food is sushi.\nAssistant: I will remember that."],
            $memory->recall(['thread-2'], 'What food do I like?'),
        );

        $memory->forget('thread-1');

        $this->assertSame([], $memory->recall(['thread-1'], 'What food do I like?'));
        $this->assertCount(1, $memory->recall(['thread-2'], 'What food do I like?'));
    }

    public function test_top_k_must_be_positive(): void
    {
        $this->expectException(AgentException::class);
        $this->expectExceptionMessage('topK must be greater than zero');

        new SemanticMemory(
            new MemoryVectorStore(),
            new FakeEmbeddingsProvider(),
            topK: 0,
        );
    }

    public function test_recall_searches_only_the_explicit_thread_ids(): void
    {
        $store = new MemoryVectorStore();
        $embeddings = new FakeEmbeddingsProvider();
        $memory = new SemanticMemory($store, $embeddings);

        $memory->remember('thread-1', 'My project is called Neuron.', 'I will remember that.');
        $memory->remember('thread-2', 'I use PHP.', 'I will remember that.');
        $memory->remember('thread-3', 'I use another framework.', 'I will remember that.');

        $memories = $memory->recall(['thread-1', 'thread-2'], 'What do you know about me?');

        $this->assertCount(2, $memories);
        $this->assertStringContainsString('Neuron', $memories[0]);
        $this->assertStringContainsString('PHP', $memories[1]);

        $memory->forget('thread-1');

        $this->assertSame(
            ["User: I use PHP.\nAssistant: I will remember that."],
            $memory->recall(['thread-1', 'thread-2'], 'What do you know about me?'),
        );
    }
}
