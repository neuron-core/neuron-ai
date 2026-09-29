<?php

declare(strict_types=1);

namespace NeuronAI\Tests\RAG\DataLoader\Stub;

use NeuronAI\Exceptions\DataReaderException;
use NeuronAI\RAG\DataLoader\ReaderInterface;

/**
 * Fails like a reader whose extraction broke, e.g. a missing pdftotext.
 */
class FailingReader implements ReaderInterface
{
    public function read(string $filePath): string
    {
        throw new DataReaderException('extraction failed');
    }
}
