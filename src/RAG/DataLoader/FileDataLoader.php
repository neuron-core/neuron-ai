<?php

declare(strict_types=1);

namespace NeuronAI\RAG\DataLoader;

use NeuronAI\Exceptions\DataReaderException;
use NeuronAI\RAG\Document;
use Exception;
use Throwable;

use function array_key_exists;
use function closedir;
use function file_exists;
use function is_array;
use function is_dir;
use function opendir;
use function pathinfo;
use function readdir;
use function strtolower;

use const PATHINFO_EXTENSION;

class FileDataLoader extends AbstractDataLoader
{
    /**
     * @var array<string, ReaderInterface>
     */
    protected array $readers = [];

    /**
     * @throws DataReaderException
     */
    public function __construct(protected string $path, array $readers = [])
    {
        parent::__construct();
        $this->setReaders($readers);

        if (! file_exists($this->path)) {
            throw new DataReaderException('The provided path does not exist: ' . $this->path);
        }
    }

    /**
     * @param string|string[] $fileExtension
     */
    public function addReader(string|array $fileExtension, ReaderInterface $reader): self
    {
        $extensions = is_array($fileExtension) ? $fileExtension : [$fileExtension];

        foreach ($extensions as $extension) {
            $this->readers[$extension] = $reader;
        }

        return $this;
    }

    public function setReaders(array $readers): self
    {
        $this->readers = $readers;
        return $this;
    }

    public function getDocuments(): array
    {
        // If it's a directory
        if (is_dir($this->path)) {
            return $this->getDocumentsFromDirectory($this->path);
        }

        // If it's a file
        try {
            return $this->splitter->splitDocument($this->getDocument($this->getContentFromFile($this->path), $this->path));
        } catch (Throwable) {
            return [];
        }
    }

    protected function getDocumentsFromDirectory(string $directory): array
    {
        $documents = [];
        // Open the directory
        if ($handle = opendir($directory)) {
            // Read the directory contents
            while (($entry = readdir($handle)) !== false) {
                $fullPath = $directory.'/'.$entry;
                if ($entry !== '.' && $entry !== '..') {
                    if (is_dir($fullPath)) {
                        $documents = [...$documents, ...$this->getDocumentsFromDirectory($fullPath)];
                    } else {
                        $documents[] = $this->getDocument($this->getContentFromFile($fullPath), $entry);
                    }
                }
            }

            // Close the directory
            closedir($handle);
        }

        return $this->splitter->splitDocuments($documents);
    }

    /**
     * Transform files to plain text.
     *
     * Supported PDF and plain text files.
     *
     * @throws Exception
     */
    protected function getContentFromFile(string $path): string|false
    {
        $fileExtension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        if (array_key_exists($fileExtension, $this->readers)) {
            $reader = $this->readers[$fileExtension];
            return $reader::getText($path);
        }

        return TextFileReader::getText($path);
    }


    protected function getDocument(string $content, string $entry): Document
    {
        $document = new Document($content);
        $document->sourceType = 'files';
        $document->sourceName = $entry;

        return $document;
    }
}
