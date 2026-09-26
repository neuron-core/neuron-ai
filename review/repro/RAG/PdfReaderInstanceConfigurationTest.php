<?php

declare(strict_types=1);

namespace NeuronAI\Tests\RAG\DataLoader;

use NeuronAI\RAG\DataLoader\FileDataLoader;
use NeuronAI\RAG\DataLoader\PdfReader;
use NeuronAI\RAG\Document;
use NeuronAI\Tests\Support\FileSystemSandbox;
use PHPUnit\Framework\TestCase;

use function array_map;
use function chmod;
use function file_put_contents;
use function mkdir;

class PdfReaderInstanceConfigurationTest extends TestCase
{
    use FileSystemSandbox;

    protected string $sandbox;

    protected string $binPath;

    protected function setUp(): void
    {
        $this->sandbox = $this->createSandbox('neuron_pdf_reader_instance');
        mkdir($this->sandbox . '/bin');
        mkdir($this->sandbox . '/docs');
        file_put_contents($this->sandbox . '/docs/document.pdf', '%PDF-1.4');

        $this->binPath = $this->sandbox . '/bin/pdftotext';
        file_put_contents($this->binPath, "#!/bin/sh\necho 'extracted by the configured binary'\n");
        chmod($this->binPath, 0o755);
    }

    protected function tearDown(): void
    {
        $this->removeSandbox($this->sandbox);
    }

    public function test_single_file_load_uses_the_configured_pdf_reader_instance(): void
    {
        $documents = FileDataLoader::for($this->sandbox . '/docs/document.pdf')
            ->addReader('pdf', new PdfReader($this->binPath))
            ->getDocuments();

        $this->assertCount(1, $documents);
        $this->assertSame('extracted by the configured binary', $documents[0]->getContent());
    }

    public function test_directory_load_uses_the_configured_pdf_reader_instance(): void
    {
        $documents = FileDataLoader::for($this->sandbox . '/docs')
            ->addReader('pdf', new PdfReader($this->binPath))
            ->getDocuments();

        $this->assertSame(
            ['extracted by the configured binary'],
            array_map(fn (Document $document): string => $document->getContent(), $documents)
        );
    }
}
