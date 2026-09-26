<?php

declare(strict_types=1);

namespace NeuronAI\Tests\RAG\Embeddings;

use NeuronAI\RAG\Document;
use NeuronAI\RAG\Embeddings\AbstractEmbeddingsProvider;
use PHPUnit\Framework\TestCase;

use function array_map;
use function mb_strlen;
use function mb_strtoupper;

class AbstractEmbeddingsProviderTest extends TestCase
{
    /** @var string[] */
    protected array $embedded = [];

    protected function provider(): AbstractEmbeddingsProvider
    {
        $embedded = &$this->embedded;

        return new class ($embedded) extends AbstractEmbeddingsProvider {
            /** @param string[] $embedded */
            public function __construct(protected array &$embedded)
            {
            }

            public function embedText(string $text): array
            {
                $this->embedded[] = $text;

                return [(float) mb_strlen($text)];
            }
        };
    }

    public function test_embed_document_sets_the_embedding_of_its_content_on_the_same_document(): void
    {
        $document = new Document('Città');

        $this->assertSame($document, $this->provider()->embedDocument($document));
        $this->assertSame([5.0], $document->getEmbedding());
        $this->assertSame(['Città'], $this->embedded);
    }

    public function test_embed_documents_embeds_each_document_in_order_and_keeps_keys(): void
    {
        $documents = ['first' => new Document('a'), 'second' => new Document('bbb')];

        $result = $this->provider()->embedDocuments($documents);

        $this->assertSame($documents, $result);
        $this->assertSame([1.0], $result['first']->getEmbedding());
        $this->assertSame([3.0], $result['second']->getEmbedding());
        $this->assertSame(['a', 'bbb'], $this->embedded);
    }

    public function test_embed_documents_without_documents_embeds_nothing(): void
    {
        $this->assertSame([], $this->provider()->embedDocuments([]));
        $this->assertSame([], $this->embedded);
    }

    public function test_embed_documents_returns_the_documents_embed_document_produced(): void
    {
        $provider = new class () extends AbstractEmbeddingsProvider {
            public function embedText(string $text): array
            {
                return [1.0];
            }

            public function embedDocument(Document $document): Document
            {
                return (new Document(mb_strtoupper($document->getContent())))->setEmbedding($this->embedText($document->getContent()));
            }
        };

        $result = $provider->embedDocuments(['first' => new Document('città'), 'second' => new Document('b')]);

        $this->assertSame(
            ['first' => 'CITTÀ', 'second' => 'B'],
            array_map(static fn (Document $document): string => $document->getContent(), $result),
        );
    }
}
