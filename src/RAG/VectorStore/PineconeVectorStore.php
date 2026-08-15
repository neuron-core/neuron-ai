<?php

declare(strict_types=1);

namespace NeuronAI\RAG\VectorStore;

use NeuronAI\Exceptions\HttpException;
use NeuronAI\HttpClient\CurlHttpClient;
use NeuronAI\HttpClient\HasHttpClient;
use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\HttpClient\HttpRequest;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\VectorStore\Filter\Compilers\PineconeFilterCompiler;
use NeuronAI\RAG\VectorStore\Filter\FilterGroup;

use function array_map;
use function in_array;
use function trim;
use function array_chunk;

class PineconeVectorStore implements VectorStoreInterface
{
    use HasHttpClient;

    public function __construct(
        string $key,
        protected string $indexUrl,
        protected int $topK = 4,
        string $version = '2025-04',
        protected string $namespace = '__default__', // Default namespace
        ?HttpClientInterface $httpClient = null,
    ) {
        $this->httpClient = ($httpClient ?? new CurlHttpClient())
            ->withBaseUri(trim($this->indexUrl, '/').'/')
            ->withHeaders([
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
                'Api-Key' => $key,
                'X-Pinecone-API-Version' => $version,
            ]);
    }

    /**
     * @throws HttpException
     */
    public function addDocument(Document $document): VectorStoreInterface
    {
        return $this->addDocuments([$document]);
    }

    /**
     * @throws HttpException
     */
    public function addDocuments(array $documents): VectorStoreInterface
    {
        $chunks = array_chunk($documents, 100);

        foreach ($chunks as $chunk) {
            $this->httpClient->request(
                HttpRequest::post(
                    uri: 'vectors/upsert',
                    body: [
                        'namespace' => $this->namespace,
                        'vectors' => array_map(fn (Document $document): array => [
                            'id' => (string) $document->getId(),
                            'values' => $document->getEmbedding(),
                            'metadata' => [
                                'content' => $document->getContent(),
                                'sourceType' => $document->getSourceType(),
                                'sourceName' => $document->getSourceName(),
                                ...$document->metadata,
                            ],
                        ], $chunk),
                    ]
                )
            );
        }

        return $this;
    }

    /**
     * @throws HttpException
     */
    public function delete(FilterGroup $filters): VectorStoreInterface
    {
        $this->httpClient->request(
            HttpRequest::post(
                uri: 'vectors/delete',
                body: [
                    'namespace' => $this->namespace,
                    'filter' => (new PineconeFilterCompiler())->compile($filters),
                ]
            )
        );

        return $this;
    }

    /**
     * @throws HttpException
     */
    public function search(SearchRequest $request): iterable
    {
        $queryParams = [
            'namespace' => $this->namespace,
            'includeMetadata' => true,
            'includeValues' => true,
            'vector' => $request->embedding,
            'topK' => $request->topK ?? $this->topK,
        ];

        if ($request->filters instanceof FilterGroup) {
            $queryParams['filter'] = (new PineconeFilterCompiler())->compile($request->filters);
        }

        $result = $this->httpClient->request(
            HttpRequest::post(
                uri: 'query',
                body: $queryParams
            )
        )->json();

        return array_map(function (array $item): Document {
            $document = new Document();
            $document->id = $item['id'];
            $document->embedding = $item['values'];
            $document->content = $item['metadata']['content'];
            $document->sourceType = $item['metadata']['sourceType'];
            $document->sourceName = $item['metadata']['sourceName'];
            $document->score = $item['score'];

            foreach ($item['metadata'] as $name => $value) {
                if (!in_array($name, ['content', 'sourceType', 'sourceName'])) {
                    $document->addMetadata($name, $value);
                }
            }

            return $document;
        }, $result['matches']);
    }
}
