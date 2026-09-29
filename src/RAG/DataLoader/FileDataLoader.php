<?php

declare(strict_types=1);

namespace NeuronAI\RAG\DataLoader;

use NeuronAI\Exceptions\DataReaderException;
use NeuronAI\RAG\Document;
use Exception;

use function array_key_exists;
use function closedir;
use function file_exists;
use function is_array;
use function is_dir;
use function is_link;
use function opendir;
use function pathinfo;
use function readdir;
use function rtrim;
use function str_starts_with;
use function strtolower;

use const PATHINFO_EXTENSION;

class FileDataLoader extends AbstractDataLoader
{
    /**
     * @var array<string, ReaderInterface>
     */
    protected array $readers;

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
        return $this->splitter->splitDocument($this->getDocument($this->getContentFromFile($this->path), $this->path));
    }

    protected function getDocumentsFromDirectory(string $directory): array
    {
        $documents = [];
        // Open the directory
        if ($handle = opendir($directory)) {
            // Read the directory contents
            while (($entry = readdir($handle)) !== false) {
                $fullPath = rtrim($directory, '/').'/'.$entry;
                // Hidden entries and symlinks met while walking are skipped: hidden
                // files hold secrets such as .env, and symlinks can loop back to an
                // ancestor or lead outside the loaded directory.
                if (! str_starts_with($entry, '.') && ! is_link($fullPath)) {
                    if (is_dir($fullPath)) {
                        $documents = [...$documents, ...$this->getDocumentsFromDirectory($fullPath)];
                    } else {
                        $documents[] = $this->getDocument($this->getContentFromFile($fullPath), $fullPath);
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
            return $this->readers[$fileExtension]->read($path);
        }

        return (new TextFileReader())->read($path);
    }


    protected function getDocument(string $content, string $path): Document
    {
        $document = new Document($content);
        $document->setSourceType('files');
        $document->setSourceName($path);

        return $document;
    }
}
