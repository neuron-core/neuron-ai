<?php

namespace NeuronAI\RAG\VectorStore;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\RequestOptions;
use NeuronAI\RAG\Document;
use function compact;
use function trim;

class QdrantVectorStore implements VectorStoreInterface
{
	protected Client $client;

	public function __construct(
		protected string $collectionUrl, // like http://localhost:6333/collections/neuron-ai/
		protected string $key,
		protected int $topK = 4,
	) {
		$this->client = new Client([
			'base_uri' => trim($this->collectionUrl, '/').'/',
			'headers' => [
				'Content-Type' => 'application/json',
				'api-key' => $this->key,
			]
		]);
	}

	/**
	 * {@inheritDoc}
	 *  @throws GuzzleException
	 */
	public function addDocument(Document $document): void
	{
		$this->client->put('points', [
			RequestOptions::JSON => [
				'points' => [
					[
						'id' => $document->id,
						'payload' => [
							'content' => $document->content,
							'sourceType' => $document->sourceType,
							'sourceName' => $document->sourceName,
						],
						'vector' => $document->embedding,
					]
				]
			]
		]);
	}

	/**
	 * {@inheritDoc}
	 * @throws GuzzleException
	 */
	public function addDocuments(array $documents): void
	{
		$points = \array_map(fn ($document) => [
			'id' => $document->id,
			'payload' => [
				'content' => $document->content,
				'sourceType' => $document->sourceType,
				'sourceName' => $document->sourceName,
			],
			'vector' => $document->embedding,
		], $documents);

		$this->client->put('points', [
			RequestOptions::JSON => [
				'operations' => [
					['upsert' => compact('points')]
				],
			]
		]);
	}

	/**
	 * {@inheritDoc}
	 */
	public function similaritySearch(array $embedding): iterable
	{
		$response = $this->client->post('points/search', [
			RequestOptions::JSON => [
				'vector' => $embedding,
				'limit' => $this->topK,
				'with_payload' => true,
				'with_vector' => true,
			]
		])->getBody()->getContents();

		$response = \json_decode($response, true);

		return \array_map(function (array $item) {
			$document = new Document();
			$document->id = $item['id'];
			$document->embedding = $item['vector'];
			$document->content = $item['payload']['content'];
			$document->sourceType = $item['payload']['sourceType'];
			$document->sourceName = $item['payload']['sourceName'];
			$document->score = $item['score'];
			return $document;
		}, $response['result']);
	}
}
