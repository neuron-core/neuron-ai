<?php

namespace NeuronAI\RAG\VectorStore;

use Exception;
use NeuronAI\RAG\Document;
use Typesense\Client;
use Typesense\Exceptions\ObjectNotFound;
use function array_chunk;
use function count;
use function implode;
use function intval;
use function json_encode;

class TypesenseVectorStore implements VectorStoreInterface
{
	/**
	 * @throws \Exception
	 */
	public function __construct(
		protected Client $client,
		protected string $collection,
		protected int $vectorDimension,
		protected string $topK = '4',
	) {
		try {

			$this->client->collections[$collection]->retrieve();
			$this->checkVectorDimension($this->vectorDimension);
			return;

		} catch (ObjectNotFound) {
			$this->client->collections->create([
				'name' => $collection,
				'fields' => [
					[
						'name' => 'content',
						'type' => 'string',
					],
					[
						'name' => 'sourceType',
						'type' => 'string',
						'facet' => true,
					],
					[
						'name' => 'sourceName',
						'type' => 'string',
						'facet' => true,
					],
					[
						'name' => 'hash',
						'type' => 'string',
						'facet' => true,
					],
					[
						'name' => 'chunkNumber',
						'type' => 'int32',
					],
					[
						'name' => 'embedding',
						'type' => 'float[]',
						'num_dim' => $this->vectorDimension,
					]
				]
			]);
		}
	}

	/**
	 * {@inheritDoc}
	 * @throws \Exception
	 */
	public function addDocument(Document $document): void
	{
		if ($document->embedding === null) {
			throw new \Exception('document embedding must be set before adding a document');
		}

		$this->checkVectorDimension(count((array) $document->embedding));

		$this->client->collections[$this->collection]->documents->create([
			'id' => $document->hash, // Unique ID is required
			'embedding' => $document->embedding,
			'content' => $document->content,
			'sourceType' => $document->sourceType,
			'sourceName' => $document->sourceName,
			'hash' => $document->hash,
			'chunkNumber' => $document->chunkNumber,
		]);
	}

	/**
	 * {@inheritDoc}
	 *  @throws \Exception
	 */
	public function addDocuments(array $documents, int $numberOfDocumentsPerRequest = 0): void
	{
		if ($documents === []) {
			return;
		}

		if ($documents[0]->embedding === null) {
			throw new \Exception('document embedding must be set before adding a document');
		}

		$this->checkVectorDimension(count((array) $documents[0]->embedding));

		$lines = [];
		foreach ($documents as $document) {
			$lines[] = json_encode([
				'id' => $document->hash, // Unique ID is required
				'embedding' => $document->embedding,
				'content' => $document->content,
				'sourceType' => $document->sourceType,
				'sourceName' => $document->sourceName,
				'hash' => $document->hash,
				'chunkNumber' => $document->chunkNumber,
			]);
		}

		$ndjson = implode("\n", $lines);

		if ($numberOfDocumentsPerRequest > 0) {
			$chunks = array_chunk($lines, $numberOfDocumentsPerRequest);
			foreach ($chunks as $chunk) {
				$chunkNdjson = implode("\n", $chunk);
				$this->client->collections[$this->collection]->documents->import($chunkNdjson);
			}
		} else {
			$this->client->collections[$this->collection]->documents->import($ndjson);
		}
	}

	/**
	 * {@inheritDoc}
	 */
	public function similaritySearch(array $embedding): array
	{
		$params = [
			'collection' => $this->collection,
			'q' => '*',
			'vector_query' => 'embedding:(' . json_encode($embedding) . ')',
			'exclude_fields' => 'embedding',
			'per_page' => $this->topK,
			'num_candidates' => \max(50, intval($this->topK) * 4),
		];

		$searchRequests = ['searches' => [$params]];

		// Search parameters that are common to all searches go here
		$commonSearchParams =  [];

		$response = $this->client->multiSearch->perform($searchRequests, $commonSearchParams);
		return \array_map(function (array $hit) {
			$item = $hit['document'];
			$document = new Document($item['content']);
			// $document->embedding = $docData['embedding']; // avoid large transfers
			$document->sourceType = $item['sourceType'];
			$document->sourceName = $item['sourceName'];
			$document->hash = $item['hash'];
			$document->chunkNumber = $item['chunkNumber'];
			$document->score = 1 - $hit['vector_distance'];
			return $document;
		}, $response['results'][0]['hits']);
	}

	/**
	 * @param int $dimension
	 *
	 * @return void
	 * @throws Exception
	 */
	private function checkVectorDimension(int $dimension): void
	{
		$schema = $this->client->collections[$this->collection]->retrieve();

		$embeddingField = null;

		foreach ($schema['fields'] as $field) {
			if ($field['name'] === 'embedding') {
				$embeddingField = $field;
				break;
			}
		}

		if (
			\array_key_exists('num_dim', $embeddingField)
			&& $embeddingField['num_dim'] === $dimension
		) {
			return;
		}

		throw new \Exception(
			"Vector embeddings dimension {$dimension} must be the same as the initial setup {$this->vectorDimension} - ".
			json_encode($embeddingField)
		);
	}
}
