<?php

declare(strict_types=1);

namespace NeuronAI\Tests\RAG\Retrieval;

use Generator;
use NeuronAI\Chat\Enums\SourceType;
use NeuronAI\Chat\Messages\ContentBlocks\ImageContent;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\Retrieval\SimilarityRetrieval;
use NeuronAI\RAG\VectorStore\SearchRequest;
use NeuronAI\Testing\FakeEmbeddingsProvider;
use NeuronAI\Testing\FakeVectorStore;
use NeuronAI\Tests\RAG\VectorStore\Stub\FixedResultsVectorStore;
use PHPUnit\Framework\TestCase;

class SimilarityRetrievalTest extends TestCase
{
    public function test_the_query_text_is_embedded_and_searched_with_the_store_default_top_k(): void
    {
        $embeddings = new FakeEmbeddingsProvider();
        $store = new FakeVectorStore([new Document('Context')]);

        (new SimilarityRetrieval($store, $embeddings))->retrieve(new UserMessage('Città più grande?'));

        $this->assertSame(['Città più grande?'], $embeddings->getRecorded());
        $request = $store->getRecorded()[0]->request;
        $this->assertInstanceOf(SearchRequest::class, $request);
        $this->assertSame((new FakeEmbeddingsProvider())->embedText('Città più grande?'), $request->embedding);
        $this->assertNull($request->topK);
    }

    public function test_a_question_without_text_retrieves_nothing_without_embedding_or_searching(): void
    {
        $embeddings = new FakeEmbeddingsProvider();
        $store = new FakeVectorStore([new Document('Context')]);

        $result = (new SimilarityRetrieval($store, $embeddings))
            ->retrieve(new UserMessage(new ImageContent('https://example.com/cat.png', SourceType::URL)));

        $this->assertSame([], $result);
        $embeddings->assertNothingEmbedded();
        $store->assertSearchCount(0);
    }

    public function test_the_store_results_are_returned_as_they_are(): void
    {
        $documents = [new Document('First'), new Document('Second')];

        $result = (new SimilarityRetrieval(new FakeVectorStore($documents), new FakeEmbeddingsProvider()))
            ->retrieve(new UserMessage('Question'));

        $this->assertSame($documents, $result);
    }

    public function test_iterable_store_results_become_a_list(): void
    {
        $documents = [new Document('First'), new Document('Second')];
        $store = new FixedResultsVectorStore(static function () use ($documents): Generator {
            yield from $documents;
        });

        $result = (new SimilarityRetrieval($store, new FakeEmbeddingsProvider()))->retrieve(new UserMessage('Question'));

        $this->assertSame($documents, $result);
    }

    public function test_every_page_of_a_paged_generator_is_retrieved(): void
    {
        $pages = [[new Document('First'), new Document('Second')], [new Document('Third')]];
        // Each yield from restarts the keys at 0
        $store = new FixedResultsVectorStore(static function () use ($pages): Generator {
            foreach ($pages as $page) {
                yield from $page;
            }
        });

        $result = (new SimilarityRetrieval($store, new FakeEmbeddingsProvider()))->retrieve(new UserMessage('Question'));

        $this->assertSame([...$pages[0], ...$pages[1]], $result);
    }

    public function test_a_keyed_result_array_becomes_a_list(): void
    {
        $first = new Document('First');
        $second = new Document('Second');
        $store = new FixedResultsVectorStore(static fn (): array => ['doc-a' => $first, 'doc-b' => $second]);

        $result = (new SimilarityRetrieval($store, new FakeEmbeddingsProvider()))->retrieve(new UserMessage('Question'));

        $this->assertSame([$first, $second], $result);
    }
}
