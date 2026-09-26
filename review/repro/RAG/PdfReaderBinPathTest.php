<?php

declare(strict_types=1);

namespace NeuronAI\Tests\RAG\DataLoader;

use NeuronAI\Exceptions\DataReaderException;
use NeuronAI\RAG\DataLoader\PdfReader;
use NeuronAI\Tests\RAG\DataLoader\Stub\PdfReaderWithoutSystemBinaries;
use NeuronAI\Tests\Support\FileSystemSandbox;
use PHPUnit\Framework\TestCase;

use function chmod;
use function file_put_contents;
use function mkdir;

class PdfReaderBinPathTest extends TestCase
{
    use FileSystemSandbox;

    protected string $sandbox;

    protected function setUp(): void
    {
        $this->sandbox = $this->createSandbox('neuron_pdf_reader_bin_path');
        file_put_contents($this->sandbox . '/document.pdf', '%PDF-1.4');
    }

    protected function tearDown(): void
    {
        $this->removeSandbox($this->sandbox);
    }

    public function test_the_configured_binary_is_executed_whatever_its_file_name(): void
    {
        $binPath = $this->fakeBinary($this->sandbox . '/pdftotext-24.02', 'configured');

        $text = (new PdfReaderWithoutSystemBinaries($binPath))->setPdf($this->sandbox . '/document.pdf')->text();

        $this->assertSame('configured', $text);
    }

    public function test_the_configured_binary_wins_over_a_system_pdftotext(): void
    {
        mkdir($this->sandbox . '/system');
        $this->fakeBinary($this->sandbox . '/system/pdftotext', 'system');
        $binPath = $this->fakeBinary($this->sandbox . '/pdftotext-wrapper', 'configured');

        $reader = new class ($binPath, $this->sandbox . '/system') extends PdfReader {
            public function __construct(string $binPath, string $systemPath)
            {
                $this->commonBasePaths = [$systemPath];
                parent::__construct($binPath);
            }
        };

        $this->assertSame('configured', $reader->setPdf($this->sandbox . '/document.pdf')->text());
    }

    public function test_a_directory_is_rejected_as_bin_path(): void
    {
        $this->expectException(DataReaderException::class);
        $this->expectExceptionMessage('The provided path is not executable.');

        new PdfReaderWithoutSystemBinaries($this->sandbox);
    }

    protected function fakeBinary(string $path, string $output): string
    {
        file_put_contents($path, "#!/bin/sh\necho '{$output}'\n");
        chmod($path, 0o755);

        return $path;
    }
}
