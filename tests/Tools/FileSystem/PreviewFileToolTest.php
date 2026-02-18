<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools\FileSystem;

use NeuronAI\Tools\Toolkits\FileSystem\PreviewFileTool;
use NeuronAI\Tools\ToolPropertyInterface;
use PHPUnit\Framework\TestCase;

use function array_map;
use function file_put_contents;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;
use function rename;
use function str_repeat;

class PreviewFileToolTest extends TestCase
{
    private PreviewFileTool $tool;

    protected function setUp(): void
    {
        $this->tool = new PreviewFileTool();
    }

    public function testPreviewNonExistentFile(): void
    {
        $result = ($this->tool)('/non/existent/file.txt');

        $this->assertStringStartsWith("Error: File '/non/existent/file.txt' does not exist.", $result);
    }

    public function testPreviewEmptyTextFile(): void
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'neuron_test_');
        file_put_contents($tempFile, '');

        $result = ($this->tool)($tempFile);
        unlink($tempFile);

        $this->assertStringContainsString('[Full content shown: 0 characters]', $result);
    }

    public function testPreviewSmallTextFile(): void
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'neuron_test_');
        $content = 'Small content';
        file_put_contents($tempFile, $content);

        $result = ($this->tool)($tempFile);
        unlink($tempFile);

        $this->assertStringStartsWith($content, $result);
        $this->assertStringContainsString('[Full content shown: 13 characters]', $result);
    }

    public function testPreviewTextFileWithDefaultMaxChars(): void
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'neuron_test_');
        $content = str_repeat('A', 5000);
        file_put_contents($tempFile, $content);

        $result = ($this->tool)($tempFile);
        unlink($tempFile);

        // Default max_chars is 3000
        $this->assertStringContainsString(str_repeat('A', 3000), $result);
        $this->assertStringContainsString('[Preview shown: 3000 of 5000 characters - use parse_file for complete content]', $result);
    }

    public function testPreviewTextFileWithCustomMaxChars(): void
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'neuron_test_');
        $content = str_repeat('B', 1000);
        file_put_contents($tempFile, $content);

        $result = ($this->tool)($tempFile, 500);
        unlink($tempFile);

        $this->assertStringContainsString(str_repeat('B', 500), $result);
        $this->assertStringContainsString('[Preview shown: 500 of 1000 characters - use parse_file for complete content]', $result);
    }

    public function testPreviewTextFileWithZeroMaxChars(): void
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'neuron_test_');
        $content = 'Test content';
        file_put_contents($tempFile, $content);

        $result = ($this->tool)($tempFile, 0);
        unlink($tempFile);

        $this->assertStringContainsString('[Preview shown: 0 of 12 characters - use parse_file for complete content]', $result);
    }

    public function testPreviewHtmlFile(): void
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'neuron_test_');
        $htmlContent = '<html><body><h1>Test</h1><p>Content</p></body></html>';
        file_put_contents($tempFile, $htmlContent);
        rename($tempFile, $tempFile . '.html');
        $htmlFile = $tempFile . '.html';

        $result = ($this->tool)($htmlFile);
        unlink($htmlFile);

        // HtmlReader converts HTML to markdown-like text (headings to uppercase)
        $this->assertStringContainsString('TEST', $result);
        $this->assertStringContainsString('Content', $result);
    }

    public function testPreviewHtmExtension(): void
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'neuron_test_');
        $htmlContent = '<html><body><h1>Htm Test</h1></body></html>';
        file_put_contents($tempFile, $htmlContent);
        rename($tempFile, $tempFile . '.htm');
        $htmFile = $tempFile . '.htm';

        $result = ($this->tool)($htmFile);
        unlink($htmFile);

        $this->assertStringContainsString('HTM TEST', $result);
    }

    public function testPreviewPdfFile(): void
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'neuron_test_');
        rename($tempFile, $tempFile . '.pdf');
        $pdfFile = $tempFile . '.pdf';

        $result = ($this->tool)($pdfFile);
        unlink($pdfFile);

        // Empty PDF will return empty content or error, depending on pdf parser
        $this->assertNotEmpty($result);
    }

    public function testPreviewPdfExtension(): void
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'neuron_test_');
        rename($tempFile, $tempFile . '.PDF');
        $pdfFile = $tempFile . '.PDF';

        $result = ($this->tool)($pdfFile);
        unlink($pdfFile);

        $this->assertNotEmpty($result);
    }

    public function testPreviewCaseInsensitiveExtension(): void
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'neuron_test_');
        $content = 'Test content';
        file_put_contents($tempFile, $content);
        rename($tempFile, $tempFile . '.TXT');
        $txtFile = $tempFile . '.TXT';

        $result = ($this->tool)($txtFile);
        unlink($txtFile);

        $this->assertStringContainsString('Test content', $result);
    }

    public function testPreviewLargeHtmlFileWithMaxChars(): void
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'neuron_test_');
        $htmlContent = '<html><body>' . str_repeat('<p>Paragraph</p>', 100) . '</body></html>';
        file_put_contents($tempFile, $htmlContent);
        rename($tempFile, $tempFile . '.html');
        $htmlFile = $tempFile . '.html';

        $result = ($this->tool)($htmlFile, 1000);
        unlink($htmlFile);

        $this->assertStringContainsString('[Preview shown:', $result);
    }

    public function testPreviewUnknownExtensionAsText(): void
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'neuron_test_');
        $content = 'Custom extension content';
        file_put_contents($tempFile, $content);
        rename($tempFile, $tempFile . '.xyz');
        $xyzFile = $tempFile . '.xyz';

        $result = ($this->tool)($xyzFile);
        unlink($xyzFile);

        $this->assertStringContainsString('Custom extension content', $result);
    }

    public function testToolProperties(): void
    {
        $this->assertEquals('preview_file', $this->tool->getName());
        $this->assertEquals('Get a quick preview of a document file. Reads only the first portion of the document content for initial relevance assessment before doing a full parse.', $this->tool->getDescription());

        $properties = $this->tool->getProperties();
        $this->assertCount(2, $properties);

        $propertyNames = array_map(fn (ToolPropertyInterface $prop): string => $prop->getName(), $properties);
        $this->assertContains('file_path', $propertyNames);
        $this->assertContains('max_chars', $propertyNames);
    }
}
