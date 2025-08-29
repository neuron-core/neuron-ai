<?php

declare(strict_types=1);

namespace NeuronAI\RAG\VectorStore;

use NeuronAI\Exceptions\VectorStoreException;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\VectorSimilarity;

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

    protected function getFilePath(): string
    {
        return $this->directory . \DIRECTORY_SEPARATOR . $this->name.$this->ext;
    }

    public function addDocument(Document $document): VectorStoreInterface
    {
        return $this->addDocuments([$document]);
    }

    public function addDocuments(array $documents): VectorStoreInterface
    {
        $this->appendToFile(
            \array_map(fn (Document $document): array => $document->jsonSerialize(), $documents)
        );
        return $this;
    }

    public function deleteBySource(string $sourceType, string $sourceName): VectorStoreInterface
    {
        // Temporary file
        $tmpFile = $this->directory . \DIRECTORY_SEPARATOR . $this->name.'_tmp'.$this->ext;

        // Create a temporary file handle
        $tempHandle = \fopen($tmpFile, 'w');
        if (!$tempHandle) {
            throw new \RuntimeException("Cannot create temporary file: {$tmpFile}");
        }

        try {
            foreach ($this->getLine($this->getFilePath()) as $line) {
                $document = \json_decode((string) $line, true);

                if ($document['sourceType'] !== $sourceType || $document['sourceName'] !== $sourceName) {
                    \fwrite($tempHandle, (string) $line);
                }
            }
        } finally {
            \fclose($tempHandle);
        }

        // Replace the original file with the filtered version
        \unlink($this->getFilePath());
        if (!\rename($tmpFile, $this->getFilePath())) {
            throw new VectorStoreException(self::class." failed to replace original file.");
        }

        return $this;
    }

    public function similaritySearch(array $embedding): array
    {
        $topItems = [];

        foreach ($this->getLine($this->getFilePath()) as $document) {
            $document = \json_decode((string) $document, true);

            if (empty($document['embedding'])) {
                throw new VectorStoreException("Document with the following content has no embedding: {$document['content']}");
            }
            $dist = VectorSimilarity::cosineDistance($embedding, $document['embedding']);

            $topItems[] = ['dist' => $dist, 'document' => $document];

            \usort($topItems, fn (array $a, array $b): int => $a['dist'] <=> $b['dist']);

            if (\count($topItems) > $this->topK) {
                $topItems = \array_slice($topItems, 0, $this->topK, true);
            }
        }

        return \array_map(function (array $item): Document {
            $itemDoc = $item['document'];
            $document = new Document($itemDoc['content']);
            $document->embedding = $itemDoc['embedding'];
            $document->sourceType = $itemDoc['sourceType'];
            $document->sourceName = $itemDoc['sourceName'];
            $document->id = $itemDoc['id'];
            $document->score = VectorSimilarity::similarityFromDistance($item['dist']);
            $document->metadata = $itemDoc['metadata'] ?? [];

            return $document;
        }, $topItems);
    }

    protected function appendToFile(array $documents): void
    {
        \file_put_contents(
            $this->getFilePath(),
            \implode(\PHP_EOL, \array_map(fn (array $vector) => \json_encode($vector), $documents)).\PHP_EOL,
            \FILE_APPEND
        );
    }

    protected function getLine(string $filename): \Generator
    {
        $f = \fopen($filename, 'r');

        try {
            while ($line = \fgets($f)) {
                yield $line;
            }
        } finally {
            \fclose($f);
        }
    }
}
