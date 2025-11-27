<?php

declare(strict_types=1);

namespace NeuronAI\Tests\DataLoader;

use NeuronAI\Exceptions\DataReaderException;
use NeuronAI\RAG\DataLoader\PdfReader;
use NeuronAI\RAG\DataLoader\ReaderInterface;
use PHPUnit\Framework\TestCase;

use function is_executable;

use const PHP_EOL;

class PdfReaderTest extends TestCase
{
    public function skipIfPdfToTextNotFound(): void
    {
        $commonPaths = [
            '/usr/bin/pdftotext',          // Common on Linux
            '/usr/local/bin/pdftotext',    // Common on Linux
            '/opt/homebrew/bin/pdftotext', // Homebrew on macOS (Apple Silicon)
            '/opt/local/bin/pdftotext',    // MacPorts on macOS
            '/usr/local/bin/pdftotext',    // Homebrew on macOS (Intel)
        ];

        foreach ($commonPaths as $path) {
            if (is_executable($path)) {
                return;
            }
        }

        $this->markTestSkipped('The pdftotext binary was not found on this machine.');
    }

    public function test_instance(): void
    {
        $this->skipIfPdfToTextNotFound();
        $instance = new PdfReader();
        $this->assertInstanceOf(PdfReader::class, $instance);
        $this->assertInstanceOf(ReaderInterface::class, $instance);
    }

    public function test_set_pdf(): void
    {
        $this->skipIfPdfToTextNotFound();
        $instance = new PdfReader();

        $instance = $instance->setPdf(__DIR__ . '/test.pdf');
        $this->assertInstanceOf(PdfReader::class, $instance);
    }

    public function test_set_pdf_exception(): void
    {
        $this->skipIfPdfToTextNotFound();
        $instance = new PdfReader();

        $this->expectException(DataReaderException::class);
        $instance->setPdf('');
    }

    public function test_get_text(): void
    {
        $this->skipIfPdfToTextNotFound();
        $instance = new PdfReader();
        $text = $instance->getText(__DIR__ . '/test.pdf');
        $this->assertStringEqualsFile(__DIR__. '/target.txt', $text . PHP_EOL);
    }

    public function test_get_text_with_image(): void
    {
        $this->skipIfPdfToTextNotFound();
        $instance = new PdfReader();
        $text = $instance->getText(__DIR__ . '/test-with-image.pdf');
        $this->assertStringEqualsFile(__DIR__. '/target.txt', $text . PHP_EOL);
    }

    public function test_get_text_exception(): void
    {
        $this->skipIfPdfToTextNotFound();
        $instance = new PdfReader();

        $this->expectException(DataReaderException::class);
        $instance->getText(__DIR__.'/test.pdf', ['binPath' => 'path/to/bin']);
    }

    public function test_get_page_count(): void
    {
        $this->skipIfPdfToTextNotFound();
        $instance = new PdfReader();
        $pdfPath = __DIR__ . '/test.pdf';

        $expectedPageCount = 1; // Matches the test.pdf file

        $pageCount = $instance->getPageCount($pdfPath);
        $this->assertSame($expectedPageCount, $pageCount);
    }

}
