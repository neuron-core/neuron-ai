<?php

declare(strict_types=1);

namespace NeuronAI\RAG\VectorStore;

use Closure;
use JsonException;
use NeuronAI\Exceptions\VectorStoreException;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\Schema\DocumentSchema;
use NeuronAI\RAG\Schema\DocumentSchemaException;
use NeuronAI\RAG\VectorSimilarity;
use NeuronAI\RAG\VectorStore\Filter\FilterEvaluator;
use NeuronAI\RAG\VectorStore\Filter\FilterExpression;
use Generator;

use function array_map;
use function array_slice;
use function chmod;
use function count;
use function fclose;
use function fgets;
use function file_exists;
use function fileperms;
use function flock;
use function fopen;
use function fstat;
use function ftruncate;
use function fwrite;
use function implode;
use function in_array;
use function is_dir;
use function is_file;
use function json_decode;
use function rename;
use function str_ends_with;
use function strlen;
use function strpbrk;
use function tempnam;
use function touch;
use function trim;
use function unlink;
use function usort;
use function mkdir;
use function json_encode;

use const DIRECTORY_SEPARATOR;
use const JSON_THROW_ON_ERROR;
use const LOCK_EX;
use const PHP_EOL;

class FileVectorStore implements VectorStoreInterface
{
    use HasDocumentSchema;

    public function __construct(
        protected string $directory,
        protected int $topK = 4,
        protected string $name = 'neuron',
        protected string $ext = '.store',
        ?DocumentSchema $schema = null,
    ) {
        $this->initializeSchema($schema);
        $fileName = $this->name.$this->ext;
        if (in_array($fileName, ['', '.', '..'], true) || strpbrk($fileName, "/\\\0") !== false) {
            throw new VectorStoreException("Store name '{$fileName}' must be a file name, not a path: put folders in \$directory.");
        }
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0o755, true)) {
            throw new VectorStoreException("Directory '{$this->directory}' does not exist and could not be created.");
        }
        if (!file_exists($this->getFilePath()) && !@touch($this->getFilePath())) {
            throw new VectorStoreException("Store file '{$this->getFilePath()}' does not exist and could not be created.");
        }
    }

    protected function getFilePath(): string
    {
        return $this->directory . DIRECTORY_SEPARATOR . $this->name.$this->ext;
    }

    public function addDocument(Document $document): VectorStoreInterface
    {
        return $this->addDocuments([$document]);
    }

    /**
     * @throws VectorStoreException
     */
    public function addDocuments(array $documents): VectorStoreInterface
    {
        $this->validateDocuments($documents);
        // Encoded outside the appendToFile() arguments: on PHP 8.1 a first-class callable that throws
        // while nested in a pending method call's arguments double-frees the documents (segfault)
        $rows = implode('', array_map($this->encodeRow(...), $documents));
        $this->appendToFile($rows);
        return $this;
    }

    /**
     * @throws VectorStoreException
     * @throws DocumentSchemaException
     */
    public function delete(FilterExpression $filters): VectorStoreInterface
    {
        $this->validateFilters($filters);
        (new FilterEvaluator())->assertEvaluable($filters);

        $this->exclusively(function () use ($filters): void {
            $this->rewriteWithout($filters);
        });

        return $this;
    }

    /**
     * @throws VectorStoreException
     * @throws DocumentSchemaException
     */
    public function search(SearchRequest $request): array
    {
        $topItems = [];
        $topK = $request->topK ?? $this->topK;
        $filters = $request->filters;
        $evaluator = new FilterEvaluator();

        if ($filters instanceof FilterExpression) {
            $this->validateFilters($filters);
            $evaluator->assertEvaluable($filters);
        }

        foreach ($this->getLine($this->getFilePath()) as $document) {
            $document = json_decode((string) $document, true);

            if ($filters instanceof FilterExpression && !$evaluator->matches($filters, $this->filterFields($document))) {
                continue;
            }

            if (empty($document['embedding'])) {
                throw new VectorStoreException("Document with the following content has no embedding: {$document['content']}");
            }
            $dist = VectorSimilarity::cosineDistance($request->embedding, $document['embedding']);

            $topItems[] = ['dist' => $dist, 'document' => $document];

            usort($topItems, fn (array $a, array $b): int => $a['dist'] <=> $b['dist']);

            if (count($topItems) > $topK) {
                $topItems = array_slice($topItems, 0, $topK, true);
            }
        }

        return array_map(function (array $item): Document {
            $itemDoc = $item['document'];
            $document = new Document($itemDoc['content']);
            $document->setEmbedding($itemDoc['embedding'])
                ->setSourceType($itemDoc['sourceType'])
                ->setSourceName($itemDoc['sourceName'])
                ->setId($itemDoc['id'])
                ->setScore(VectorSimilarity::similarityFromDistance($item['dist']))
                ->setMetadata($itemDoc['metadata'] ?? []);

            return $document;
        }, $topItems);
    }

    /**
     * @param array<string, mixed> $document A decoded storage row.
     * @return array<string, mixed>
     */
    protected function filterFields(array $document): array
    {
        return [
            'content' => $document['content'] ?? null,
            'sourceType' => $document['sourceType'] ?? null,
            'sourceName' => $document['sourceName'] ?? null,
            ...($document['metadata'] ?? []),
        ];
    }

    /**
     * Score is deliberately excluded: it belongs to a retrieval operation,
     * not to the stored document.
     *
     * @return array<string, mixed>
     */
    protected function storedDocument(Document $document): array
    {
        return [
            'id' => $document->getId(),
            'content' => $document->getContent(),
            'embedding' => $document->getEmbedding(),
            'sourceType' => $document->getSourceType(),
            'sourceName' => $document->getSourceName(),
            'metadata' => $document->getMetadata(),
        ];
    }

    /**
     * One line per document, its newline included. Every row of a batch is encoded before
     * the file is touched, so a document the file cannot hold refuses the whole batch.
     *
     * @throws VectorStoreException
     */
    protected function encodeRow(Document $document): string
    {
        try {
            return json_encode($this->storedDocument($document), JSON_THROW_ON_ERROR).PHP_EOL;
        } catch (JsonException $exception) {
            throw new VectorStoreException("Document {$document->getId()} is not JSON serializable: {$exception->getMessage()}", $exception->getCode(), $exception);
        }
    }

    /**
     * @throws VectorStoreException
     */
    protected function appendToFile(string $rows): void
    {
        $this->exclusively(function () use ($rows): void {
            $handle = @fopen($this->getFilePath(), 'a');
            if ($handle === false) {
                throw new VectorStoreException("Store file '{$this->getFilePath()}' could not be written.");
            }

            try {
                $size = fstat($handle)['size'];

                if (@fwrite($handle, $rows) !== strlen($rows)) {
                    // Take back a partial row (a full disk): the next append would continue it
                    ftruncate($handle, $size);
                    throw new VectorStoreException("Store file '{$this->getFilePath()}' could not be written.");
                }
            } finally {
                fclose($handle);
            }
        });
    }

    /**
     * Copies the rows that don't match into a file of its own, then renames it over the store
     * in one step: a reader finds the old rows or the new ones, never a missing file.
     *
     * @throws VectorStoreException
     */
    protected function rewriteWithout(FilterExpression $filters): void
    {
        $evaluator = new FilterEvaluator();

        // A unique new file: never another store's file, never a symlink planted in the directory
        $tmpFile = @tempnam($this->directory, '.vectors-');
        if ($tmpFile === false) {
            throw new VectorStoreException("Cannot create temporary file in: {$this->directory}");
        }

        try {
            $tempHandle = fopen($tmpFile, 'w');

            try {
                foreach ($this->getLine($this->getFilePath()) as $line) {
                    $document = json_decode((string) $line, true);

                    if (!$evaluator->matches($filters, $this->filterFields($document))) {
                        fwrite($tempHandle, (string) $line);
                    }
                }
            } finally {
                fclose($tempHandle);
            }

            // tempnam() creates the file readable by its owner only: keep the store's permissions
            chmod($tmpFile, fileperms($this->getFilePath()) & 0o777);

            if (!rename($tmpFile, $this->getFilePath())) {
                throw new VectorStoreException(self::class." failed to replace original file.");
            }
        } finally {
            if (is_file($tmpFile)) {
                unlink($tmpFile);
            }
        }
    }

    /**
     * Runs a write while holding the store's lock file, so writers take turns: a delete never
     * loses rows appended while it copies, and two deletes never interleave. Readers take no
     * lock: rows are only ever appended, or replaced by rename().
     *
     * @throws VectorStoreException
     */
    protected function exclusively(Closure $write): void
    {
        $lockFile = $this->getFilePath().'.lock';
        $lock = @fopen($lockFile, 'c');
        if ($lock === false) {
            throw new VectorStoreException("Cannot open lock file: {$lockFile}");
        }

        try {
            flock($lock, LOCK_EX);
            $write();
        } finally {
            fclose($lock);
        }
    }

    protected function getLine(string $filename): Generator
    {
        $f = fopen($filename, 'r');

        try {
            // A row counts once its newline is written: a last line without one is an append in progress
            while (($line = fgets($f)) !== false && str_ends_with($line, "\n")) {
                // Blank lines hold no row: older versions wrote one for an empty batch or a document they could not encode
                if (trim($line) !== '') {
                    yield $line;
                }
            }
        } finally {
            fclose($f);
        }
    }
}
