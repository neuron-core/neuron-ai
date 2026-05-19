<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools\FileSystem;

use NeuronAI\Tools\Toolkits\FileSystem\ParseFileTool;
use NeuronAI\Tools\ToolPropertyInterface;
use PHPUnit\Framework\TestCase;

use function array_map;
use function file_put_contents;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;
use function rename;

class ParseFileToolTest extends TestCase
{
    private ParseFileTool $tool;

    protected function setUp(): void
    {
        $this->tool = new ParseFileTool();
    }

    public function testParseNonExistentFile(): void
    {
        $result = ($this->tool)('/non/existent/file.txt');

        $this->assertStringStartsWith("Error: File '/non/existent/file.txt' does not exist.", $result);
    }

    public function testParsePdfFile(): void
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'neuron_test_');
        rename($tempFile, $tempFile . '.pdf');
        $pdfFile = $tempFile . '.pdf';

        $result = ($this->tool)($pdfFile);
        unlink($pdfFile);

        // Empty or minimal PDF - result is a string
        $this->assertNotEmpty($result);
    }

    public function testParsePdfExtension(): void
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'neuron_test_');
        rename($tempFile, $tempFile . '.PDF');
        $pdfFile = $tempFile . '.PDF';

        $result = ($this->tool)($pdfFile);
        unlink($pdfFile);

        $this->assertNotEmpty($result);
    }

    public function testParseHtmlFile(): void
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'neuron_test_');
        $htmlContent = '<html><body><h1>Parse Test</h1><p>This is content</p></body></html>';
        file_put_contents($tempFile, $htmlContent);
        rename($tempFile, $tempFile . '.html');
        $htmlFile = $tempFile . '.html';

        $result = ($this->tool)($htmlFile);
        unlink($htmlFile);

        // HtmlReader converts headings to uppercase
        $this->assertStringContainsString('PARSE TEST', $result);
        $this->assertStringContainsString('This is content', $result);
        $this->assertStringContainsString('[HTML parsed successfully:', $result);
    }

    public function testParseHtmExtension(): void
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'neuron_test_');
        $htmlContent = '<html><body><h1>HTM Test</h1></body></html>';
        file_put_contents($tempFile, $htmlContent);
        rename($tempFile, $tempFile . '.htm');
        $htmFile = $tempFile . '.htm';

        $result = ($this->tool)($htmFile);
        unlink($htmFile);

        $this->assertStringContainsString('HTM TEST', $result);
        $this->assertStringContainsString('[HTML parsed successfully:', $result);
    }

    public function testParseHtmlWithComplexStructure(): void
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'neuron_test_');
        $htmlContent = <<<'HTML'
<!DOCTYPE html>
<html>
<head><title>Test Page</title></head>
<body>
    <nav>
        <ul>
            <li>Home</li>
            <li>About</li>
            <li>Contact</li>
        </ul>
    </nav>
    <main>
        <h1>Main Heading</h1>
        <p>Paragraph text.</p>
    </main>
</body>
</html>
HTML;
        file_put_contents($tempFile, $htmlContent);
        rename($tempFile, $tempFile . '.html');
        $htmlFile = $tempFile . '.html';

        $result = ($this->tool)($htmlFile);
        unlink($htmlFile);

        // HtmlReader converts headings to uppercase
        $this->assertStringContainsString('MAIN HEADING', $result);
        $this->assertStringContainsString('Paragraph text.', $result);
        $this->assertStringContainsString('Home', $result);
    }

    public function testParseCaseInsensitiveExtension(): void
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'neuron_test_');
        $htmlContent = '<html><body><h1>Case Test</h1></body></html>';
        file_put_contents($tempFile, $htmlContent);
        rename($tempFile, $tempFile . '.HTML');
        $htmlFile = $tempFile . '.HTML';

        $result = ($this->tool)($htmlFile);
        unlink($htmlFile);

        $this->assertStringContainsString('CASE TEST', $result);
        $this->assertStringContainsString('[HTML parsed successfully:', $result);
    }

    public function testParseUnsupportedExtension(): void
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'neuron_test_');
        $content = 'Unsupported file content';
        file_put_contents($tempFile, $content);
        rename($tempFile, $tempFile . '.xyz');
        $xyzFile = $tempFile . '.xyz';

        $result = ($this->tool)($xyzFile);
        unlink($xyzFile);

        $this->assertStringStartsWith('Error: Unsupported file format', $result);
        $this->assertStringContainsString("'xyz'", $result);
    }

    public function testParseTxtExtensionReturnsError(): void
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'neuron_test_');
        $content = 'Text file content';
        file_put_contents($tempFile, $content);
        rename($tempFile, $tempFile . '.txt');
        $txtFile = $tempFile . '.txt';

        $result = ($this->tool)($txtFile);
        unlink($txtFile);

        // TXT is not a supported format for parse_file (only PDF, HTML)
        $this->assertStringStartsWith('Error: Unsupported file format', $result);
    }

    public function testParsePhpExtensionReturnsError(): void
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'neuron_test_');
        $content = '<?php echo "hello"; ?>';
        file_put_contents($tempFile, $content);
        rename($tempFile, $tempFile . '.php');
        $phpFile = $tempFile . '.php';

        $result = ($this->tool)($phpFile);
        unlink($phpFile);

        $this->assertStringStartsWith('Error: Unsupported file format', $result);
    }

    public function testToolProperties(): void
    {
        $this->assertEquals('parse_file', $this->tool->getName());
        $this->assertEquals('Parse and return the complete content of a document file. Use this after preview_file confirms the document is relevant, or when you need to find cross-references to other documents. Supported formats: PDF, HTML.', $this->tool->getDescription());

        $properties = $this->tool->getProperties();
        $this->assertCount(1, $properties);

        $propertyNames = array_map(fn (ToolPropertyInterface $prop): string => $prop->getName(), $properties);
        $this->assertContains('file_path', $propertyNames);
    }
}
