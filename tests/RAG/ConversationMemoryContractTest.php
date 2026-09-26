<?php

declare(strict_types=1);

namespace NeuronAI\Tests\RAG;

use Closure;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\RAG;
use NeuronAI\RAG\Retrieval\CompositeRetrieval;
use NeuronAI\RAG\Retrieval\SemanticMemoryRetrieval;
use NeuronAI\RAG\Retrieval\SimilarityRetrieval;
use NeuronAI\RAG\VectorStore\FileVectorStore;
use NeuronAI\RAG\VectorStore\Filter\Filter;
use NeuronAI\RAG\VectorStore\Filter\FilterGroup;
use NeuronAI\RAG\VectorStore\MemoryVectorStore;
use NeuronAI\RAG\VectorStore\VectorStoreInterface;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Testing\FakeEmbeddingsProvider;
use NeuronAI\Tests\RAG\Nodes\Stub\ConversationAgent;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function bin2hex;
use function glob;
use function is_dir;
use function random_bytes;
use function rmdir;
use function sys_get_temp_dir;
use function unlink;

/**
 * Conversation memory is written by one component (ConversationIngestionNode
 * at the end of an Agent turn) and read by another (SemanticMemoryRetrieval in
 * a later RAG turn), through a real vector store. The documents the writer
 * produces must be exactly what the reader's thread allowlist selects: the
 * authorized thread's exchange is recalled, no other thread's ever is, and the
 * documented deletion removes it.
 */
class ConversationMemoryContractTest extends TestCase
{
    protected const KNOWLEDGE = 'Lockers are on the second floor.';

    protected const ALICE_MEMORY = "Source Type: conversation\nSource Name: alice-thread\n"
        ."Content: User: My locker code is 4417.\nAssistant: Noted, your code is 4417.\n\n";

    protected string $directory;

    protected FakeEmbeddingsProvider $embeddings;

    protected VectorStoreInterface $knowledge;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/neuron_memory_contract_'.bin2hex(random_bytes(6));
        $this->embeddings = new FakeEmbeddingsProvider();
        $this->knowledge = new MemoryVectorStore();
        $this->knowledge->addDocument($this->embeddings->embedDocument(
            (new Document(self::KNOWLEDGE))->setSourceType('manual')->setSourceName('office.md'),
        ));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory.'/*') ?: [] as $file) {
            unlink($file);
        }
        if (is_dir($this->directory)) {
            rmdir($this->directory);
        }
    }

    /**
     * Each factory returns how a new process opens the conversation store.
     *
     * @return array<string, array{Closure(string): (Closure(): VectorStoreInterface)}>
     */
    public static function conversationStores(): array
    {
        return [
            'memory' => [static function (string $directory): Closure {
                $store = new MemoryVectorStore(topK: 10);

                return static fn (): VectorStoreInterface => $store;
            }],
            'file' => [static fn (string $directory): Closure => static fn (): VectorStoreInterface => new FileVectorStore($directory, topK: 10)],
        ];
    }

    /**
     * @param Closure(): VectorStoreInterface $openStore
     */
    protected function remember(Closure $openStore): void
    {
        $agent = ConversationAgent::make(workflowId: 'alice-thread');
        $agent->conversationStore = $openStore();
        $agent->conversationEmbeddings = $this->embeddings;
        $agent->setAiProvider(new FakeAIProvider(new AssistantMessage('Noted, your code is 4417.')))
            ->chat(new UserMessage('My locker code is 4417.'));
    }

    /**
     * A RAG turn in $threadId recalling the conversations of $authorizedThreads
     * alongside the knowledge base; returns the context the model received.
     *
     * @param Closure(): VectorStoreInterface $openStore
     * @param string[] $authorizedThreads
     */
    protected function recall(Closure $openStore, string $threadId, array $authorizedThreads): string
    {
        $provider = new FakeAIProvider(new AssistantMessage('Answer'));
        RAG::make(workflowId: $threadId)
            ->setAiProvider($provider)
            ->setInstructions('Help the user.')
            ->setRetrieval(new CompositeRetrieval([
                new SemanticMemoryRetrieval($openStore(), $this->embeddings, $authorizedThreads),
                new SimilarityRetrieval($this->knowledge, $this->embeddings),
            ]))
            ->chat(new UserMessage('Where is my locker and what is its code?'));

        return (string) $provider->getRecorded()[0]->systemPrompt?->getContent();
    }

    /**
     * @param Closure(string): (Closure(): VectorStoreInterface) $prepareStore
     */
    #[DataProvider('conversationStores')]
    public function test_an_ingested_exchange_is_recalled_only_in_authorized_threads(Closure $prepareStore): void
    {
        $openStore = $prepareStore($this->directory);
        $this->remember($openStore);

        $this->assertSame(
            "Help the user.\n\n<EXTRA-CONTEXT>".self::ALICE_MEMORY
            ."Source Type: manual\nSource Name: office.md\nContent: ".self::KNOWLEDGE."\n\n</EXTRA-CONTEXT>",
            $this->recall($openStore, 'alice-new-thread', ['alice-thread']),
        );

        $bobContext = $this->recall($openStore, 'bob-thread', ['bob-thread']);
        $this->assertStringContainsString(self::KNOWLEDGE, $bobContext);
        $this->assertStringNotContainsString('4417', $bobContext, 'Another thread must never recall this conversation.');

        // The current thread is not implicitly authorized either.
        $this->assertStringNotContainsString('4417', $this->recall($openStore, 'alice-thread', ['alice-other-thread']));
    }

    /**
     * @param Closure(string): (Closure(): VectorStoreInterface) $prepareStore
     */
    #[DataProvider('conversationStores')]
    public function test_the_documented_deletion_forgets_only_the_selected_conversation(Closure $prepareStore): void
    {
        $openStore = $prepareStore($this->directory);
        $this->remember($openStore);
        $openStore()->addDocument($this->embeddings->embedDocument(
            (new Document('User: My desk is 12.'))->setSourceType(SemanticMemoryRetrieval::SOURCE_TYPE)->setSourceName('carol-thread'),
        ));

        $openStore()->delete(FilterGroup::and(
            Filter::eq('sourceType', SemanticMemoryRetrieval::SOURCE_TYPE),
            Filter::eq('sourceName', 'alice-thread'),
        ));

        $this->assertStringNotContainsString('4417', $this->recall($openStore, 'alice-new-thread', ['alice-thread', 'carol-thread']));
        $this->assertStringContainsString('My desk is 12.', $this->recall($openStore, 'carol-new-thread', ['carol-thread']));
    }
}
