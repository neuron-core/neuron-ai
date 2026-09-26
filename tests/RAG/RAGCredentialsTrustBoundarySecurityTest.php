<?php

declare(strict_types=1);

namespace NeuronAI\Tests\RAG;

use GuzzleHttp\Psr7\Response;
use NeuronAI\Chat\History\InMemoryMessageStore;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Observability\ObservabilityEvent;
use NeuronAI\Providers\OpenAI\OpenAI;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\Embeddings\OpenAIEmbeddingsProvider;
use NeuronAI\RAG\PostProcessor\JinaRerankerPostProcessor;
use NeuronAI\RAG\PreProcessor\QueryTransformationPreProcessor;
use NeuronAI\RAG\RAG;
use NeuronAI\Testing\FakeVectorStore;
use NeuronAI\Tests\Support\RecordsHttpRequests;
use NeuronAI\Workflow\Persistence\InMemoryPersistence;
use PHPUnit\Framework\TestCase;

use function array_keys;
use function array_map;
use function implode;
use function json_encode;
use function preg_replace;
use function serialize;

use const JSON_THROW_ON_ERROR;

/**
 * Every remote component of a RAG turn (query rewriting, embeddings, reranking,
 * inference) authenticates with its own key. Each key reaches only its own
 * vendor, in its authentication header, and none reaches what the run keeps or
 * publishes.
 */
class RAGCredentialsTrustBoundarySecurityTest extends TestCase
{
    use RecordsHttpRequests;

    protected const CHAT_KEY = 'sk-chat-SECRET-11aa';
    protected const REWRITE_KEY = 'sk-rewrite-SECRET-22bb';
    protected const EMBEDDINGS_KEY = 'sk-embed-SECRET-33cc';
    protected const RERANK_KEY = 'jina-rerank-SECRET-44dd';

    /** @var string[] */
    protected array $publishedEvents = [];

    protected function completion(string $content): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], json_encode([
            'id' => 'chatcmpl-1',
            'choices' => [['index' => 0, 'finish_reason' => 'stop', 'message' => ['role' => 'assistant', 'content' => $content]]],
            'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1, 'total_tokens' => 2],
        ], JSON_THROW_ON_ERROR));
    }

    protected function rag(InMemoryPersistence $persistence, InMemoryMessageStore $messageStore): RAG
    {
        $rag = RAG::make(workflowId: 'rag-credentials')
            ->setAiProvider(new OpenAI(self::CHAT_KEY, 'model', httpClient: $this->recordingClient($this->completion('Paris.'))))
            ->setPreProcessors([new QueryTransformationPreProcessor(
                new OpenAI(self::REWRITE_KEY, 'model', httpClient: $this->recordingClient($this->completion('capital of France'))),
            )])
            ->setEmbeddingsProvider(new OpenAIEmbeddingsProvider(self::EMBEDDINGS_KEY, 'embedder', httpClient: $this->recordingClient(
                new Response(200, ['Content-Type' => 'application/json'], '{"data":[{"index":0,"embedding":[0.1,0.2,0.3]}]}'),
            )))
            ->setVectorStore(new FakeVectorStore([
                new Document('Paris is the capital of France.'),
                new Document('Rome is the capital of Italy.'),
            ]))
            ->setPostProcessors([new JinaRerankerPostProcessor(self::RERANK_KEY, topN: 1, httpClient: $this->recordingClient(
                new Response(200, ['Content-Type' => 'application/json'], '{"results":[{"index":0,"relevance_score":0.9}]}'),
            ))])
            ->setPersistence($persistence)
            ->setMessageStore($messageStore)
            ->retainCompletionUntilAcknowledged();

        $rag->subscribe(ObservabilityEvent::class, function (ObservabilityEvent $event): void {
            $this->publishedEvents[] = $event->name().' '.json_encode($event->toArray(), JSON_THROW_ON_ERROR);
        });

        return $rag;
    }

    public function test_each_key_reaches_only_its_own_vendor_and_nothing_the_run_keeps_or_publishes(): void
    {
        $persistence = new InMemoryPersistence();
        $messageStore = new InMemoryMessageStore();

        $state = $this->rag($persistence, $messageStore)->chat(new UserMessage('What is the capital of France?'));

        $this->assertSame('Paris.', $state->getMessage()?->getContent());

        // Rewrite, embed the rewritten query, rerank the retrieved documents, answer.
        $this->assertSame([
            ['POST https://api.openai.com/v1/chat/completions', self::REWRITE_KEY],
            ['POST https://api.openai.com/v1/embeddings', self::EMBEDDINGS_KEY],
            ['POST https://api.jina.ai/v1/rerank', self::RERANK_KEY],
            ['POST https://api.openai.com/v1/chat/completions', self::CHAT_KEY],
        ], array_map(
            fn (int $index): array => [$this->sentTargets()[$index], $this->bearerOf($index)],
            array_keys($this->sentRequests),
        ));

        $keys = [self::CHAT_KEY, self::REWRITE_KEY, self::EMBEDDINGS_KEY, self::RERANK_KEY];
        foreach ($this->sentRequests as $index => $entry) {
            $request = $entry['request'];
            foreach ($keys as $key) {
                $this->assertStringNotContainsString($key, (string) $request->getUri(), "Request {$index} carries a key in its URL.");
                $this->assertStringNotContainsString($key, (string) $request->getBody(), "Request {$index} carries a key in its body.");
                if ($key !== $this->bearerOf($index)) {
                    $this->assertStringNotContainsString($key, implode("\n", array_map(
                        static fn (array $values): string => implode(', ', $values),
                        $request->getHeaders(),
                    )), "Request {$index} carries another component's key.");
                }
            }
        }

        $this->assertStringContainsString('rag-postprocessing {"processor":'.json_encode(JinaRerankerPostProcessor::class, JSON_THROW_ON_ERROR), implode("\n", $this->publishedEvents));
        $this->assertStringContainsString('rag-preprocessing {"processor":'.json_encode(QueryTransformationPreProcessor::class, JSON_THROW_ON_ERROR), implode("\n", $this->publishedEvents));

        $surfaces = [
            'the returned state' => serialize($state),
            'the persisted run' => serialize($persistence),
            'the chat history' => serialize($messageStore),
            'the observability payloads' => implode("\n", $this->publishedEvents),
        ];
        foreach ($surfaces as $surface => $content) {
            foreach ($keys as $key) {
                $this->assertStringNotContainsString($key, $content, "A key leaks into {$surface}.");
            }
        }
    }

    protected function bearerOf(int $request): string
    {
        return (string) preg_replace('/^Bearer /', '', $this->sentRequests[$request]['request']->getHeaderLine('Authorization'));
    }
}
