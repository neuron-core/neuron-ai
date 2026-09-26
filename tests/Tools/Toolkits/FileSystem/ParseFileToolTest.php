<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools\Toolkits\FileSystem;

use NeuronAI\Tests\Support\FileSystemSandbox;
use NeuronAI\Tests\Support\ToolErrorAssertions;
use NeuronAI\Tools\Toolkits\FileSystem\ParseFileTool;
use NeuronAI\Tools\ToolOutput;
use NeuronAI\Tools\ToolPropertyInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function array_map;
use function file_put_contents;
use function mb_strlen;
use function mkdir;
use function preg_match;

class ParseFileToolTest extends TestCase
{
    use FileSystemSandbox;
    use ToolErrorAssertions;

    protected ParseFileTool $tool;

    protected string $tempDir;

    protected function setUp(): void
    {
        $this->tool = new ParseFileTool();
        $this->tempDir = $this->createSandbox('neuron_parse');
    }

    protected function tearDown(): void
    {
        $this->removeSandbox($this->tempDir);
    }

    public function test_parse_non_existent_file(): void
    {
        $this->assertToolError("File '/non/existent/file.txt' does not exist.", ($this->tool)('/non/existent/file.txt'));
    }

    public function test_directory_with_a_document_extension_is_not_parsed(): void
    {
        mkdir($this->tempDir . '/site.html');

        $this->assertToolError("File 'site.html' does not exist.", (new ParseFileTool($this->tempDir))('site.html'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function pdfExtensionProvider(): iterable
    {
        yield 'lowercase' => ['pdf'];
        yield 'uppercase' => ['PDF'];
    }

    #[DataProvider('pdfExtensionProvider')]
    public function test_unparseable_pdf_is_reported_as_a_failure(string $extension): void
    {
        $file = $this->tempDir . '/broken.' . $extension;
        file_put_contents($file, 'not a pdf');

        $result = ($this->tool)($file);

        $this->assertInstanceOf(ToolOutput::class, $result);
        $this->assertTrue($result->isError());
        $this->assertStringStartsWith("Unable to parse PDF file '{$file}'.", $result->getText());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function htmlExtensionProvider(): iterable
    {
        yield 'html' => ['html'];
        yield 'htm' => ['htm'];
        yield 'uppercase' => ['HTML'];
    }

    #[DataProvider('htmlExtensionProvider')]
    public function test_html_is_parsed_to_text_followed_by_its_character_count(string $extension): void
    {
        $file = $this->tempDir . '/page.' . $extension;
        file_put_contents($file, '<html><body><h1>Parse Test</h1><p>This is content</p></body></html>');

        $result = ($this->tool)($file);

        $this->assertIsString($result);
        $this->assertSame(1, preg_match('/^(.*)\n\n\[HTML parsed successfully: (\d+) characters\]$/s', $result, $parts));
        $this->assertStringContainsString('PARSE TEST', $parts[1]);
        $this->assertStringContainsString('This is content', $parts[1]);
        $this->assertStringNotContainsString('<h1>', $parts[1]);
        $this->assertSame((string) mb_strlen($parts[1]), $parts[2]);
    }

    public function test_html_with_complex_structure(): void
    {
        $file = $this->tempDir . '/page.html';
        file_put_contents($file, <<<'HTML'
            <!DOCTYPE html>
            <html>
            <head><title>Test Page</title></head>
            <body>
                <nav><ul><li>Home</li><li>About</li></ul></nav>
                <main>
                    <h1>Main Heading</h1>
                    <p>Paragraph text.</p>
                </main>
            </body>
            </html>
            HTML);

        $result = ($this->tool)($file);

        $this->assertStringContainsString('MAIN HEADING', $result);
        $this->assertStringContainsString('Paragraph text.', $result);
        $this->assertStringContainsString('Home', $result);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function unsupportedFileProvider(): iterable
    {
        yield 'unknown extension' => ['file.xyz', 'xyz'];
        yield 'plain text' => ['file.txt', 'txt'];
        yield 'php source' => ['file.php', 'php'];
        yield 'no extension' => ['Makefile', ''];
        yield 'extension hidden before a dot' => ['report.html.txt', 'txt'];
    }

    #[DataProvider('unsupportedFileProvider')]
    public function test_unsupported_formats_are_refused(string $name, string $extension): void
    {
        file_put_contents($this->tempDir . '/' . $name, '<?php echo "hello"; ?>');

        $this->assertToolError(
            "Unsupported file format '{$extension}'. Supported formats: PDF, HTML. If the file is already in plain text you can access its content directly with other tools like grep_file_content or read_file.",
            ($this->tool)($this->tempDir . '/' . $name)
        );
    }

    public function test_file_outside_the_scope_is_not_parsed(): void
    {
        mkdir($this->tempDir . '/scope');
        file_put_contents($this->tempDir . '/secret.html', '<p>secret</p>');

        $this->assertToolError(
            "Access denied: '../secret.html' is outside the working scope '{$this->tempDir}/scope'.",
            (new ParseFileTool($this->tempDir . '/scope'))('../secret.html')
        );
    }

    public function test_tool_properties(): void
    {
        $this->assertSame('parse_file', $this->tool->getName());
        $this->assertSame(
            ['file_path'],
            array_map(fn (ToolPropertyInterface $prop): string => $prop->getName(), $this->tool->getProperties())
        );
    }
}
