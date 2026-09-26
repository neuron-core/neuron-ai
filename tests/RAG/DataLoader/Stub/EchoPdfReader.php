<?php

declare(strict_types=1);

namespace NeuronAI\Tests\RAG\DataLoader\Stub;

use NeuronAI\RAG\DataLoader\PdfReader;

/**
 * Runs echo in place of pdftotext, so the extracted "text" is the received arguments.
 */
class EchoPdfReader extends PdfReader
{
    protected function findPdfToText(): string
    {
        return '/bin/echo';
    }
}
