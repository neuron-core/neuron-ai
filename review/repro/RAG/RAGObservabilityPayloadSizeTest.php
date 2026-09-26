<?php

declare(strict_types=1);

namespace NeuronAI\Tests\RAG;

use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Observability\LogListener;
use NeuronAI\Observability\ObservabilityEvent;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\RAG;
use NeuronAI\RAG\VectorStore\MemoryVectorStore;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Testing\FakeEmbeddingsProvider;
use NeuronAI\Tests\RAG\Stub\LimitPostProcessor;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Stringable;

use function json_encode;
use function str_contains;
use function str_starts_with;
use function strlen;

use const JSON_THROW_ON_ERROR;

class RAGObservabilityPayloadSizeTest extends TestCase
{
    public function test_logged_retrieval_payloads_do_not_carry_embedding_vectors(): void
    {
        $embeddings = new FakeEmbeddingsProvider(1536);
        $store = new MemoryVectorStore();
        $store->addDocuments($embeddings->embedDocuments([new Document('First'), new Document('Second')]));

        $rag = RAG::make()
            ->setEmbeddingsProvider($embeddings)
            ->setVectorStore($store)
            ->setPostProcessors([new LimitPostProcessor(1)]);
        $rag->setAiProvider(new FakeAIProvider(new AssistantMessage('Answer')));

        $logger = new class () extends AbstractLogger {
            /** @var array<string, string> */
            public array $records = [];

            public function log($level, string|Stringable $message, array $context = []): void
            {
                if (str_starts_with((string) $message, 'rag-')) {
                    $this->records[(string) $message] = json_encode($context, JSON_THROW_ON_ERROR);
                }
            }
        };
        $rag->subscribe(ObservabilityEvent::class, new LogListener($logger));

        $rag->chat(new UserMessage('Question'));

        foreach (['rag-retrieved', 'rag-postprocessing', 'rag-postprocessed'] as $name) {
            $this->assertArrayHasKey($name, $logger->records);
            $this->assertFalse(str_contains($logger->records[$name], '"embedding":['), "{$name} logs full embedding vectors (" . strlen($logger->records[$name]) . ' bytes)');
        }
    }
}
