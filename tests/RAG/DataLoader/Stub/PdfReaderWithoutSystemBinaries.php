<?php

declare(strict_types=1);

namespace NeuronAI\Tests\RAG\DataLoader\Stub;

use NeuronAI\RAG\DataLoader\PdfReader;

/**
 * Looks for the poppler binaries next to the configured bin path only, so tests
 * do not depend on what happens to be installed on the machine.
 */
class PdfReaderWithoutSystemBinaries extends PdfReader
{
    protected array $commonBasePaths = [];
}
