<?php

declare(strict_types=1);

namespace NeuronAI\RAG\VectorStore;

use NeuronAI\Exceptions\VectorStoreException;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\Schema\DocumentSchema;
use NeuronAI\RAG\Schema\DocumentSchemaException;
use NeuronAI\RAG\VectorStore\Filter\FilterExpression;
use NeuronAI\RAG\VectorStore\Filter\FilterValidator;

trait HasDocumentSchema
{
    protected DocumentSchema $schema;

    protected function initializeSchema(?DocumentSchema $schema): void
    {
        $this->schema = $schema ?? DocumentSchema::default();
    }

    public function getSchema(): DocumentSchema
    {
        return $this->schema;
    }

    /**
     * @throws VectorStoreException
     * @throws DocumentSchemaException
     */
    protected function validateDocument(Document $document, bool $embeddingRequired = true): void
    {
        $this->schema->validate($document);

        if ($embeddingRequired && $document->getEmbedding() === null) {
            throw new VectorStoreException(
                "Document {$document->getId()} must have an embedding before it can be stored."
            );
        }
    }

    /**
     * @param Document[] $documents
     * @throws VectorStoreException
     */
    protected function validateDocuments(array $documents, bool $embeddingRequired = true): void
    {
        foreach ($documents as $document) {
            $this->validateDocument($document, $embeddingRequired);
        }
    }

    /**
     * @throws DocumentSchemaException
     */
    protected function validateFilters(FilterExpression $filters): void
    {
        (new FilterValidator())->validate($filters, $this->schema);
    }
}
