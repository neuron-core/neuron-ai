<?php

declare(strict_types=1);

namespace NeuronAI\RAG\DataLoader;

use NeuronAI\RAG\Document;
use NeuronAI\RAG\Schema\DocumentSchemaException;

class StringDataLoader extends AbstractDataLoader
{
    public function __construct(protected string $content)
    {
        parent::__construct();
    }

    /**
     * @throws DocumentSchemaException
     */
    public function getDocuments(): array
    {
        if ($this->content === '') {
            return [];
        }

        return $this->splitter->splitDocument(new Document($this->content));
    }
}
