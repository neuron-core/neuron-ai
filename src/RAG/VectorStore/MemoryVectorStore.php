<?php

namespace NeuronAI\RAG\VectorStore;

use NeuronAI\Exceptions\VectorStoreException;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\VectorStore\Search\SimilaritySearch;

class MemoryVectorStore implements VectorStoreInterface
{
	/** @var array<Document> */
	private array $documents = [];

	public function __construct(protected int $topK = 4)
	{
	}

	/**
	 * {@inheritDoc}
	 */
	public function addDocument(Document $document): void
	{
		$this->documents[] = $document;
	}

	/**
	 * {@inheritDoc}
	 */
	public function addDocuments(array $documents): void
	{
		$this->documents = \array_merge($this->documents, $documents);
	}

	/**
	 * {@inheritDoc}
	 */
	public function similaritySearch(array $embedding): array
	{
		$distances = [];

		foreach ($this->documents as $index => $document) {
			if ($document->embedding === null) {
				throw new VectorStoreException("Document with the following content has no embedding: {$document->content}");
			}
			$dist = $this->cosineSimilarity($embedding, $document->embedding);
			$distances[$index] = $dist;
		}

		\asort($distances); // Sort by distance (ascending).

		$topKIndices = \array_slice(\array_keys($distances), 0, $this->topK, true);

		return \array_reduce($topKIndices, function ($carry, $index) use ($distances) {
			$document = $this->documents[$index];
			$document->score = 1 - $distances[$index];
			$carry[] = $document;
			return $carry;
		}, []);
	}

	/**
	 * @param array $vector1
	 * @param array $vector2
	 *
	 * @return float
	 * @throws VectorStoreException
	 */
	public function cosineSimilarity(array $vector1, array $vector2): float
	{
		return SimilaritySearch::cosine($vector1, $vector2);
	}
}
