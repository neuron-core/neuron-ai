<?php

declare(strict_types=1);

namespace NeuronAI\RAG\DataLoader;

use function file_get_contents;

class TextFileReader implements ReaderInterface
{
    public function read(string $filePath): string
    {
        return file_get_contents($filePath);
    }
}
