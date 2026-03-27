<?php

declare(strict_types=1);

/**
 * Persistent Agent Memory with php-agent-memory
 *
 * Demonstrates three integration patterns with Neuron AI:
 * 1. MemoryToolkit   — agent decides when to save/recall (on-demand RAG)
 * 2. MemoryRetrieval — custom retrieval replacing SimilarityRetrieval
 * 3. Dream cycle     — end-of-session memory consolidation
 *
 * Install: composer require mauricioperera/php-agent-memory
 * Docs:    https://github.com/MauricioPerera/php-agent-memory
 */

use NeuronAI\Agent\Agent;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Providers\Anthropic\Anthropic;
use NeuronAI\RAG\Embeddings\EmbeddingsProviderInterface;
use NeuronAI\RAG\Embeddings\OllamaEmbeddingsProvider;
use NeuronAI\RAG\RAG;
use NeuronAI\RAG\Retrieval\RetrievalInterface;
use NeuronAI\RAG\VectorStore\VectorStoreInterface;
use PHPAgentMemory\AgentMemory;
use PHPAgentMemory\Config;
use PHPAgentMemory\Consolidation\CloudflareLlmProvider;
use PHPAgentMemory\Integration\Neuron\MemoryRetrieval;
use PHPAgentMemory\Integration\Neuron\MemoryToolkit;
use PHPAgentMemory\Integration\Neuron\NeuronMemoryStore;

require_once __DIR__ . '/../../vendor/autoload.php';

// --- Shared memory instance ---

$memory = new AgentMemory(new Config(
    dataDir: __DIR__ . '/data/memory',
    dimensions: 768,
    quantized: true,
    llmProvider: new CloudflareLlmProvider(
        accountId: \getenv('CF_ACCOUNT_ID') ?: '',
        apiToken: \getenv('CF_API_TOKEN') ?: '',
    ),
));

echo "=== Pattern 1: Agent with on-demand memory tools ===\n";
echo "-------------------------------------------------------------------\n\n";

// The model decides when to save or recall — no automatic injection.

$agent = Agent::make()
    ->setAiProvider(
        new Anthropic(
            \getenv('ANTHROPIC_API_KEY') ?: '',
            'claude-3-5-haiku-20241022'
        )
    )
    ->setInstructions(
        'You are a helpful assistant with persistent memory. '
        . 'Use memory_save to remember important facts and preferences. '
        . 'Use memory_recall before answering questions about the user.'
    )
    ->addTool(
        MemoryToolkit::make(
            $memory,
            new OllamaEmbeddingsProvider(\getenv('OLLAMA_EMBEDDINGS_MODEL') ?: 'nomic-embed-text'),
            'assistant',
            'user-1'
        )
    );

$response = $agent->chat(
    new UserMessage('My name is Mauricio and I prefer dark mode.')
)->getMessage();

echo "User: My name is Mauricio and I prefer dark mode.\n";
echo "Agent: " . $response->getContent() . "\n\n";

$response = $agent->chat(
    new UserMessage('What do you know about my preferences?')
)->getMessage();

echo "User: What do you know about my preferences?\n";
echo "Agent: " . $response->getContent() . "\n\n";


echo "=== Pattern 2: RAG with persistent memory retrieval ===\n";
echo "-------------------------------------------------------------------\n\n";

// Replaces SimilarityRetrieval with cross-collection hybrid search.

$rag = new class ($memory) extends RAG {
    public function __construct(private AgentMemory $mem)
    {
    }

    protected function provider()
    {
        return new Anthropic(
            \getenv('ANTHROPIC_API_KEY') ?: '',
            'claude-3-5-haiku-20241022'
        );
    }

    protected function embeddings(): EmbeddingsProviderInterface
    {
        return new OllamaEmbeddingsProvider(
            \getenv('OLLAMA_EMBEDDINGS_MODEL') ?: 'nomic-embed-text'
        );
    }

    protected function vectorStore(): VectorStoreInterface
    {
        return new NeuronMemoryStore($this->mem, 'assistant', 'knowledge', topK: 5);
    }

    protected function retrieval(): RetrievalInterface
    {
        return new MemoryRetrieval(
            $this->mem,
            $this->resolveEmbeddingsProvider(),
            'assistant',
            'user-1',
            maxItems: 10,
        );
    }
};

$response = $rag->chat(
    new UserMessage("What are the user's UI preferences?")
)->getMessage();

echo "User: What are the user's UI preferences?\n";
echo "Agent: " . $response->getContent() . "\n\n";


echo "=== Pattern 3: Dream — end-of-session consolidation ===\n";
echo "-------------------------------------------------------------------\n\n";

// The agent "sleeps" and wakes up with deduplicated, merged memory.

$report = $memory->dream('assistant', 'user-1');
echo $report . "\n";

echo "\n=== Example Complete ===\n";
