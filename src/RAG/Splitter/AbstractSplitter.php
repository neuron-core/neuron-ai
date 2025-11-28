<?php

declare(strict_types=1);

namespace NeuronAI\RAG\Splitter;

use NeuronAI\RAG\Document;

use function array_merge;

abstract class AbstractSplitter implements SplitterInterface
{
    /**
     * @param  Document[]  $documents
     * @return Document[]
     */
    public function splitDocuments(array $documents): array
    {
        $split = [];

        foreach ($documents as $document) {
            $split = array_merge($split, $this->splitDocument($document));
        }

        return $split;
    }
}
