<?php

declare(strict_types=1);

namespace NeuronAI\RAG\DataLoader;

use NeuronAI\Exceptions\DataReaderException;

use function file_get_contents;

class TextFileReader implements ReaderInterface
{
    /**
     * @throws DataReaderException
     */
    public function read(string $filePath): string
    {
        $content = @file_get_contents($filePath);

        if ($content === false) {
            throw new DataReaderException("Could not read `{$filePath}`. Invalid path or permission denied.");
        }

        return $content;
    }
}
