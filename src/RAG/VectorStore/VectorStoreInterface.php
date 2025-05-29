<?php

namespace NeuronAI\RAG\VectorStore;

use NeuronAI\RAG\Document;

interface VectorStoreInterface
{
	/**
	 *  Store a single document.
	 *
	 * @param Document $document
	 *
	 * @return void
	 */
	public function addDocument(Document $document): void;

	/**
	 *  Bulk save documents.
	 *
	 * @param array $documents
	 *
	 * @return void
	 */
	public function addDocuments(array $documents): void;

	/**
	 * Return docs most similar to the embedding.
	 *
	 * @param  float[]  $embedding
	 * @return array<Document>
	 */
	public function similaritySearch(array $embedding): iterable;
}
