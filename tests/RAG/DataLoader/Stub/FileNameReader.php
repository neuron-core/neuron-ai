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
    public static function getText(string $filePath, array $options = []): string
    {
        return 'read by FileNameReader: ' . basename($filePath);
    }
}
