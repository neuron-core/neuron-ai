<?php

namespace NeuronAI\RAG\VectorStore;

use Elastic\Elasticsearch\Exception\ClientResponseException;
use Elastic\Elasticsearch\Exception\ServerResponseException;
use Exception;
use NeuronAI\RAG\Document;
use Elastic\Elasticsearch\Client;
use Elastic\Elasticsearch\Response\Elasticsearch;
use function count;

class ElasticsearchVectorStore implements VectorStoreInterface
{
	protected bool $vectorDimSet = false;

	/**
	 * @throws \Exception
	 */
	public function __construct(
		protected Client $client,
		protected string $index,
		protected int $topK = 4,
	) {
		/** @var Elasticsearch $existResponse */
		$existResponse = $client->indices()->exists(['index' => $index]);
		$existStatusCode = $existResponse->getStatusCode();

		if ($existStatusCode === 200) {
			return;
		}

		$client->indices()->create([
			'index' => $index,
			'body' => [
				'mappings' => [
					'properties' => [
						'content' => [
							'type' => 'text',
						],
						'sourceType' => [
							'type' => 'keyword',
						],
						'sourceName' => [
							'type' => 'keyword',
						],
						'hash' => [
							'type' => 'keyword',
						],
						'chunkNumber' => [
							'type' => 'integer',
						],
					],
				],
			],
		]);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @throws \Exception
	 */
	public function addDocument(Document $document): void
	{
		if ($document->embedding === null) {
			throw new \Exception('document embedding must be set before adding a document');
		}

		$this->mapVectorDimension(\count($document->embedding));

		$this->client->index([
			'index' => $this->index,
			'body' => [
				'embedding' => $document->embedding,
				'content' => $document->content,
				'sourceType' => $document->sourceType,
				'sourceName' => $document->sourceName,
				'hash' => $document->hash,
				'chunkNumber' => $document->chunkNumber,
			],
		]);

		$this->client->indices()->refresh(['index' => $this->index]);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @throws \Exception
	 */
	public function addDocuments(array $documents, int $numberOfDocumentsPerRequest = 0): void
	{
		if ($documents === []) {
			return;
		}

		if ($documents[0]->embedding === null) {
			throw new \Exception('document embedding must be set before adding a document');
		}

		/*
		 * Map vector embeddings dimension on the fly adding a document.
		 */
		$this->mapVectorDimension(count($documents[0]->embedding));

		/*
		 * Generate a bulk payload
		 */
		$params = ['body' => []];
		foreach ($documents as $document) {
			$params['body'][] = [
				'index' => [
					'_index' => $this->index,
				],
			];
			$params['body'][] = [
				'embedding' => $document->embedding,
				'content' => $document->content,
				'sourceType' => $document->sourceType,
				'sourceName' => $document->sourceName,
				'hash' => $document->hash,
				'chunkNumber' => $document->chunkNumber,
			];

		}
		$this->client->bulk($params);
		$this->client->indices()->refresh(['index' => $this->index]);
	}

	/**
	 * {@inheritDoc}
	 *
	 * num_candidates are used to tune approximate kNN for speed or accuracy (see : https://www.elastic.co/guide/en/elasticsearch/reference/current/knn-search.html#tune-approximate-knn-for-speed-accuracy)
	 * @return array
	 * @throws ClientResponseException
	 * @throws ServerResponseException
	 */
	public function similaritySearch(array $embedding): array
	{
		$searchParams = [
			'index' => $this->index,
			'body' => [
				'knn' => [
					'field' => 'embedding',
					'query_vector' => $embedding,
					'k' => $this->topK,
					'num_candidates' => \max(50, $this->topK * 4),
				],
				'sort' => [
					'_score' => [
						'order' => 'desc',
					],
				],
			],
		];

		/*if (\array_key_exists('filter', $additionalArguments)) {
			$searchParams['body']['knn']['filter'] = $additionalArguments['filter'];
		}*/

		$response = $this->client->search($searchParams);

		return \array_map(function (array $item) {
			$document = new Document($item['_source']['content']);
			$document->embedding = $item['_source']['embedding'];
			$document->sourceType = $item['_source']['sourceType'];
			$document->sourceName = $item['_source']['sourceName'];
			$document->hash = $item['_source']['hash'];
			$document->chunkNumber = $item['_source']['chunkNumber'];
			$document->score = $item['_score'];
			return $document;
		}, $response['hits']['hits']);
	}

	private function mapVectorDimension(int $dimension): void
	{
		if ($this->vectorDimSet) {
			return;
		}

		$response = $this->client->indices()->getFieldMapping([
			'index' => $this->index,
			'fields' => 'embedding',
		]);

		$mappings = $response[$this->index]['mappings'];
		if (
			\array_key_exists('embedding', $mappings)
			&& $mappings['embedding']['mapping']['embedding']['dims'] === $dimension
		) {
			return;
		}

		$this->client->indices()->putMapping([
			'index' => $this->index,
			'body' => [
				'properties' => [
					'embedding' => [
						'type' => 'dense_vector',
						//'element_type' => 'float', // it's float by default
						'dims' => $dimension,
						'index' => true,
						'similarity' => 'cosine',
					],
				],
			],
		]);

		$this->vectorDimSet = true;
	}
}
