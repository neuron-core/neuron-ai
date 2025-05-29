<?php

namespace NeuronAI\RAG\VectorStore;

use GuzzleHttp\Client;
use GuzzleHttp\RequestOptions;
use NeuronAI\RAG\Document;
use function min;
use function trim;

class MeilisearchVectorStore implements VectorStoreInterface
{
	protected Client $client;

	public function __construct(
		protected string $key,
		protected string $indexUid,
		protected string $host = 'http://localhost:7700',
		protected int $topK = 5,
	) {
		$this->client = new Client([
			'base_uri' => trim($this->host, '/').'/indexes/'.$this->indexUid.'/',
			'headers' => [
				'Content-Type' => 'application/json',
				'Authorization' => "Bearer {$this->key}",
			]
		]);

		try {
			$this->client->get('');
		} catch (\Exception $exception) {
			$this->client->post(trim($this->host, '/').'/indexes/', [
				RequestOptions::JSON => [
					'uid' => $this->indexUid,
					'primaryKey' => 'id',
				]
			]);
		}
	}

	/**
	 * {@inheritDoc}
	 */
	public function addDocument(Document $document): void
	{
		$this->addDocuments([$document]);
	}

	/**
	 * {@inheritDoc}
	 */
	public function addDocuments(array $documents): void
	{
		$this->client->put('documents', [
			RequestOptions::JSON => \array_map(function (Document $document) {
				return [
					'id' => $document->id,
					'content' => $document->content,
					'sourceType' => $document->sourceType,
					'sourceName' => $document->sourceName,
					'_vectors' => [
						'default' => [
							'embeddings' => $document->embedding,
							'regenerate' => false,
						],
					]
				];
			}, $documents),
		]);
	}

	/**
	 * {@inheritDoc}
	 */
	public function similaritySearch(array $embedding): iterable
	{
		$response = $this->client->post('search', [
			RequestOptions::JSON => [
				'vector' => $embedding,
				'limit' => min($this->topK, 20),
				'retrieveVectors' => true,
				'showRankingScore' => true,
			]
		])->getBody()->getContents();

		$response = \json_decode($response, true);

		return \array_map(function (array $item) {
			$document = new Document();
			$document->id = $item['id'] ?? null;
			$document->content = $item['content'];
			$document->sourceType = $item['sourceType'] ?? null;
			$document->sourceName = $item['sourceName'] ?? null;
			$document->embedding = $item['_vectors']['default']['embeddings'];
			$document->score = $item['_rankingScore'];
			return $document;
		}, $response['hits']);
	}
}
