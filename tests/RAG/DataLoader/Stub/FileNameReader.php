<?php

declare(strict_types=1);

namespace NeuronAI\Tests\RAG\DataLoader\Stub;

use NeuronAI\RAG\DataLoader\ReaderInterface;

use function basename;

/**
 * Reveals which files it was asked to read, without looking at their content.
 */
class FileNameReader implements ReaderInterface
{
    public function __construct(protected string $label = 'FileNameReader')
    {
    }

    public function read(string $filePath): string
    {
        return "read by {$this->label}: " . basename($filePath);
    }
}
