<?php

declare(strict_types=1);

namespace NeuronAI\Tests\RAG;

use NeuronAI\Exceptions\AgentException;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\RAG;
use NeuronAI\RAG\Schema\DocumentField;
use NeuronAI\RAG\Schema\DocumentSchema;
use NeuronAI\RAG\Schema\DocumentSchemaException;
use NeuronAI\Testing\FakeEmbeddingsProvider;
use NeuronAI\Testing\FakeVectorStore;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class ReindexValidatesBeforeDeleteTest extends TestCase
{
    protected FakeVectorStore $store;
    protected RAG $rag;

    protected function setUp(): void
    {
        $this->store = new FakeVectorStore(schema: DocumentSchema::of(DocumentField::string('tenant')->required()));
        $this->rag = RAG::make()->setEmbeddingsProvider(new FakeEmbeddingsProvider())->setVectorStore($this->store);
        $this->rag->addDocuments([$this->document('Indexed')->addMetadata('tenant', 'acme')]);
    }

    public function test_an_invalid_replacement_leaves_the_indexed_source_untouched(): void
    {
        try {
            $this->rag->reindexBySource([$this->document('Missing tenant')]);
            $this->fail('The invalid replacement must be rejected.');
        } catch (DocumentSchemaException) {
        }

        $this->store->assertDocumentCount(1);
        $this->store->assertHasDocumentWithContent('Indexed');
    }

    public function test_an_invalid_chunk_size_leaves_the_indexed_source_untouched(): void
    {
        try {
            $this->rag->reindexBySource([$this->document('Replacement')->addMetadata('tenant', 'acme')], chunkSize: 0);
            $this->fail('The invalid chunk size must be rejected.');
        } catch (AgentException $exception) {
            $this->assertSame('RAG document chunk size must be greater than zero.', $exception->getMessage());
        }

        $this->store->assertDocumentCount(1);
        $this->store->assertHasDocumentWithContent('Indexed');
    }

    public function test_an_embeddings_failure_leaves_the_indexed_source_untouched(): void
    {
        $this->rag->setEmbeddingsProvider(new class () extends FakeEmbeddingsProvider {
            public function embedDocuments(array $documents): array
            {
                throw new RuntimeException('Rate limited');
            }
        });

        try {
            $this->rag->reindexBySource([$this->document('Replacement')->addMetadata('tenant', 'acme')]);
            $this->fail('The embeddings failure must propagate.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Rate limited', $exception->getMessage());
        }

        $this->store->assertDocumentCount(1);
        $this->store->assertHasDocumentWithContent('Indexed');
    }

    protected function document(string $content): Document
    {
        return (new Document($content))->setSourceType('file')->setSourceName('a.md');
    }
}
