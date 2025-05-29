<?php

namespace NeuronAI\RAG\VectorStore;

use Generator;
use NeuronAI\Exceptions\VectorStoreException;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\VectorStore\Search\SimilaritySearch;
use function compact;
use function fclose;
use function fgets;
use function fopen;
use function implode;
use const DIRECTORY_SEPARATOR;
use const FILE_APPEND;
use const PHP_EOL;

class FileVectorStore implements VectorStoreInterface
{
	public function __construct(
		protected string $directory,
		protected int $topK = 4,
		protected string $name = 'neuron',
		protected string $ext = '.store'
	) {
		if (!\is_dir($this->directory)) {
			throw new VectorStoreException("Directory '{$this->directory}' does not exist");
		}
	}

	/**
	 * @return string
	 */
	protected function getFilePath(): string
	{
		return $this->directory . DIRECTORY_SEPARATOR . $this->name.$this->ext;
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
		$this->appendToFile(
			\array_map(fn (Document $document) => $document->jsonSerialize(), $documents)
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function similaritySearch(array $embedding): array
	{
		$topItems = [];

		foreach ($this->getLine($this->getFilePath()) as $document) {
			$document = \json_decode($document, true);

			if (empty($document['embedding'])) {
				throw new VectorStoreException("Document with the following content has no embedding: {$document['content']}");
			}
			$dist = $this->cosineSimilarity($embedding, $document['embedding']);

			$topItems[] = compact('dist', 'document');

			\usort($topItems, fn ($a, $b) => $a['dist'] <=> $b['dist']);

			if (\count($topItems) > $this->topK) {
				$topItems = \array_slice($topItems, 0, $this->topK, true);
			}
		}

		return \array_reduce($topItems, function ($carry, $item) {
			$itemDoc = $item['document'];
			$document = new Document($itemDoc['content']);
			$document->embedding = $itemDoc['embedding'];
			$document->sourceType = $itemDoc['sourceType'];
			$document->sourceName = $itemDoc['sourceName'];
			$document->id = $itemDoc['id'];
			$document->score = 1 - $item['dist'];
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
	protected function cosineSimilarity(array $vector1, array $vector2): float
	{
		return SimilaritySearch::cosine($vector1, $vector2);
	}

	/**
	 * @param array $vectors
	 *
	 * @return void
	 */
	protected function appendToFile(array $vectors): void
	{
		\file_put_contents(
			$this->getFilePath(),
			implode(PHP_EOL, \array_map(fn (array $vector) => \json_encode($vector), $vectors)).PHP_EOL,
			FILE_APPEND
		);
	}

	/**
	 * @param $file
	 *
	 * @return Generator
	 */
	protected function getLine($file): \Generator
	{
		$f = fopen($file, 'r');

		try {
			while ($line = fgets($f)) {
				yield $line;
			}
		} finally {
			fclose($f);
		}
	}
}
