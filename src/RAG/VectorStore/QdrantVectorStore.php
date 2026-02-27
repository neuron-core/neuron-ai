<?php

declare(strict_types=1);

namespace NeuronAI\RAG\VectorStore;

use NeuronAI\Exceptions\HttpException;
use NeuronAI\HttpClient\GuzzleHttpClient;
use NeuronAI\HttpClient\HasHttpClient;
use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\HttpClient\HttpRequest;
use NeuronAI\RAG\Document;

use function array_map;
use function in_array;
use function is_null;
use function trim;
use function array_chunk;

class QdrantVectorStore implements VectorStoreInterface
{
    use HasHttpClient;

    /**
     * @throws HttpException
     */
    public function __construct(
        protected string $collectionUrl, // like http://localhost:6333/collections/neuron-ai/
        protected ?string $key = null,
        protected int $topK = 5,
        protected int $dimension = 1024,
        ?HttpClientInterface $httpClient = null,
    ) {
        $this->httpClient = ($httpClient ?? new GuzzleHttpClient())
            ->withBaseUri(trim($this->collectionUrl, '/').'/')
            ->withHeaders([
                'Content-Type' => 'application/json',
                ...(!is_null($this->key) && $this->key !== '' ? ['api-key' => $this->key] : [])
            ]);

        $this->initialize();
    }

    /**
     * @throws HttpException
     */
    protected function initialize(): void
    {
        $response = $this->httpClient->request(
            HttpRequest::get('exists')
        )->json();

        if ($response['result']['exists']) {
            return;
        }

        $this->createCollection();
    }

    /**
     * @throws HttpException
     */
    public function destroy(): void
    {
        $this->httpClient->request(HttpRequest::delete(''));
    }

    /**
     * @throws HttpException
     */
    public function addDocument(Document $document): VectorStoreInterface
    {
        return $this->addDocuments([$document]);
    }

    /**
     * Bulk save documents.
     *
     * @param Document[] $documents
     * @throws HttpException
     */
    public function addDocuments(array $documents): VectorStoreInterface
    {
        $points = array_map(fn (Document $document): array => [
            'id' => (int) $document->getId(),
            'payload' => [
                'content' => $document->getContent(),
                'sourceType' => $document->getSourceType(),
                'sourceName' => $document->getSourceName(),
                ...$document->metadata,
            ],
            'vector' => $document->getEmbedding(),
        ], $documents);

        $chunks = array_chunk($points, 100);

        foreach ($chunks as $chunk) {
            $this->httpClient->request(
                HttpRequest::put('points', ['points' => $chunk])
            );
        }

        return $this;
    }

    /**
     * @throws HttpException
     */
    public function deleteBySource(string $sourceType, string $sourceName): VectorStoreInterface
    {
        $this->httpClient->request(
            HttpRequest::post(
                uri: 'points/delete',
                body: [
                    'wait' => true,
                    'filter' => [
                        'must' => [
                            [
                                'key' => 'sourceType',
                                'match' => [
                                    'value' => $sourceType,
                                ]
                            ],
                            [
                                'key' => 'sourceName',
                                'match' => [
                                    'value' => $sourceName,
                                ]
                            ]
                        ]
                    ]
                ]
            )
        );

        return $this;
    }

    /**
     * @throws HttpException
     */
    public function similaritySearch(array $embedding): iterable
    {
        $response = $this->httpClient->request(
            HttpRequest::post(
                uri: 'points/query',
                body: [
                    'query' => [
                        'recommend' => ['positive' => [$embedding]]
                    ],
                    'limit' => $this->topK,
                    'with_payload' => true,
                    'with_vector' => true,
                ]
            )
        )->json();

        return array_map(function (array $item): Document {
            $document = new Document($item['payload']['content']);
            $document->id = $item['id'];
            $document->embedding = $item['vector'];
            $document->sourceType = $item['payload']['sourceType'];
            $document->sourceName = $item['payload']['sourceName'];
            $document->score = $item['score'];

            foreach ($item['payload'] as $name => $value) {
                if (!in_array($name, ['content', 'sourceType', 'sourceName', 'score', 'embedding', 'id'])) {
                    $document->addMetadata($name, $value);
                }
            }

            return $document;
        }, $response['result']['points']);
    }

    protected function createCollection(): void
    {
        $this->httpClient->request(
            HttpRequest::put(
                uri: '',
                body: [
                    'vectors' => [
                        'size' => $this->dimension,
                        'distance' => 'Cosine',
                    ],
                ],
            )
        );
    }
}
