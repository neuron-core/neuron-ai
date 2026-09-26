<?php

declare(strict_types=1);

namespace NeuronAI\Tests\RAG\DataLoader;

use NeuronAI\Exceptions\DataReaderException;
use NeuronAI\RAG\DataLoader\PdfReader;
use NeuronAI\Tests\RAG\DataLoader\Stub\EchoPdfReader;
use NeuronAI\Tests\RAG\DataLoader\Stub\PdfReaderWithoutSystemBinaries;
use NeuronAI\Tests\Support\FileSystemSandbox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Exception\InvalidArgumentException;
use Symfony\Component\Process\Exception\ProcessFailedException;

use function chdir;
use function chmod;
use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function getcwd;
use function is_executable;
use function mkdir;
use function str_replace;

use const PHP_EOL;
use const PHP_OS_FAMILY;

class PdfReaderTest extends TestCase
{
    use FileSystemSandbox;

    /**
     * Prints every received argument on its own line, wrapped in angle brackets.
     */
    protected const ARGUMENTS_ECHO = <<<'SH'
        #!/bin/sh
        for argument in "$@"; do printf '<%s>\n' "$argument"; done
        SH;

    protected string $sandbox;

    protected string $pdf;

    protected function setUp(): void
    {
        $this->sandbox = $this->createSandbox('neuron_pdf_reader');
        $this->pdf = $this->sandbox . '/document.pdf';
        file_put_contents($this->pdf, '%PDF-1.4');
    }

    protected function tearDown(): void
    {
        $this->removeSandbox($this->sandbox);
    }

    public function test_bin_path_must_be_executable(): void
    {
        file_put_contents($this->sandbox . '/pdftotext', 'not executable');
        chmod($this->sandbox . '/pdftotext', 0o644);

        $this->expectException(DataReaderException::class);
        $this->expectExceptionMessage('The provided path is not executable.');

        new PdfReader($this->sandbox . '/pdftotext');
    }

    public function test_static_get_text_validates_the_bin_path_option(): void
    {
        $this->expectException(DataReaderException::class);
        $this->expectExceptionMessage('The provided path is not executable.');

        PdfReader::getText($this->pdf, ['binPath' => $this->sandbox . '/missing/pdftotext']);
    }

    public function test_pdf_must_be_readable(): void
    {
        $missing = $this->sandbox . '/missing.pdf';

        $this->expectException(DataReaderException::class);
        $this->expectExceptionMessage("Could not read `{$missing}`. Invalid path or permission denied.");

        (new PdfReader())->setPdf($missing);
    }

    public function test_text_runs_pdftotext_from_the_bin_path_with_options_then_pdf_then_stdout(): void
    {
        $reader = new PdfReader($this->fakeBinary('pdftotext', self::ARGUMENTS_ECHO));

        $text = $reader->setPdf($this->pdf)->setOptions(['layout', '-f 1', ' -l 2 '])->text();

        $this->assertSame("<-layout>\n<-f>\n<1>\n<-l>\n<2>\n<{$this->pdf}>\n<->", $text);
    }

    public function test_option_values_containing_spaces_stay_a_single_argument(): void
    {
        $reader = new PdfReader($this->fakeBinary('pdftotext', self::ARGUMENTS_ECHO));

        $text = $reader->setPdf($this->pdf)->setOptions(['opw my secret'])->text();

        $this->assertSame("<-opw>\n<my secret>\n<{$this->pdf}>\n<->", $text);
    }

    public function test_add_options_appends_while_set_options_replaces(): void
    {
        $reader = (new PdfReader($this->fakeBinary('pdftotext', self::ARGUMENTS_ECHO)))->setPdf($this->pdf);

        $appended = $reader->setOptions(['layout'])->addOptions(['raw'])->text();
        $replaced = $reader->setOptions(['nopgbrk'])->text();

        $this->assertSame("<-layout>\n<-raw>\n<{$this->pdf}>\n<->", $appended);
        $this->assertSame("<-nopgbrk>\n<{$this->pdf}>\n<->", $replaced);
    }

    public function test_pdf_path_is_passed_as_one_argument_without_shell_interpretation(): void
    {
        $hostile = $this->sandbox . '/a $(touch pwned) `touch pwned` ; touch pwned.pdf';
        file_put_contents($hostile, '%PDF-1.4');
        $reader = new PdfReader($this->fakeBinary('pdftotext', self::ARGUMENTS_ECHO));

        // The process runs in the current directory, where an injected relative `touch pwned` would land.
        $workingDirectory = (string) getcwd();
        chdir($this->sandbox);
        try {
            $text = $reader->setPdf($hostile)->text();
        } finally {
            chdir($workingDirectory);
        }

        $this->assertSame("<{$hostile}>\n<->", $text);
        $this->assertFileDoesNotExist($this->sandbox . '/pwned');
        $this->assertFileDoesNotExist($this->sandbox . '/pwned.pdf');
    }

    public function test_text_trims_page_breaks_and_surrounding_whitespace(): void
    {
        $reader = new PdfReader($this->fakeBinary('pdftotext', "#!/bin/sh\nprintf '\\f\\n  Page one\\n\\fPage two \\n\\f\\n'"));

        $this->assertSame("Page one\n\fPage two", $reader->setPdf($this->pdf)->text());
    }

    public function test_failed_extraction_raises_a_process_exception(): void
    {
        $reader = new PdfReader($this->fakeBinary('pdftotext', "#!/bin/sh\necho 'Syntax Error: broken PDF' >&2\nexit 3"));

        try {
            $reader->setPdf($this->pdf)->text();
            $this->fail('A failed pdftotext run must not be treated as extracted text.');
        } catch (ProcessFailedException $exception) {
            $this->assertSame(3, $exception->getProcess()->getExitCode());
            $this->assertStringContainsString('Syntax Error: broken PDF', $exception->getMessage());
        }
    }

    public function test_configured_timeout_is_applied_to_the_pdftotext_process(): void
    {
        $reader = (new PdfReader($this->fakeBinary('pdftotext', self::ARGUMENTS_ECHO)))->setPdf($this->pdf);

        // A negative timeout is rejected by the process only once it is applied, which proves the
        // value reaches it without waiting for a real timeout to expire.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The timeout value must be a valid positive integer or float number.');

        $reader->setTimeout(-1)->text();
    }

    public function test_static_get_text_applies_the_timeout_option(): void
    {
        $this->expectException(InvalidArgumentException::class);

        PdfReader::getText($this->pdf, ['binPath' => $this->fakeBinary('pdftotext', self::ARGUMENTS_ECHO), 'timeout' => -1]);
    }

    public function test_static_get_text_builds_the_reader_it_is_called_on(): void
    {
        if (!is_executable('/bin/echo')) {
            $this->markTestSkipped('/bin/echo is not available on this platform.');
        }

        $this->assertSame("{$this->pdf} -", EchoPdfReader::getText($this->pdf));
    }

    public function test_static_get_text_applies_bin_path_and_options(): void
    {
        $text = PdfReader::getText($this->pdf, [
            'binPath' => $this->fakeBinary('pdftotext', self::ARGUMENTS_ECHO),
            'options' => ['layout'],
            'timeout' => 5,
        ]);

        $this->assertSame("<-layout>\n<{$this->pdf}>\n<->", $text);
    }

    public function test_missing_pdftotext_binary_is_reported(): void
    {
        $this->expectException(DataReaderException::class);
        $this->expectExceptionMessage('The pdftotext binary was not found or is not executable.');

        (new PdfReaderWithoutSystemBinaries())->setPdf($this->pdf)->text();
    }

    public function test_page_count_is_read_from_pdfinfo_next_to_the_bin_path(): void
    {
        $binPath = $this->fakeBinary('pdftotext', self::ARGUMENTS_ECHO);
        $this->fakeBinary('pdfinfo', "#!/bin/sh\n[ \"\$1\" = '{$this->pdf}' ] || exit 1\nprintf 'Title:          Report 2024\\nPages:          12\\nFile size:      5000 bytes\\n'");

        $this->assertSame(12, (new PdfReaderWithoutSystemBinaries($binPath))->getPageCount($this->pdf));
    }

    public function test_page_count_requires_a_pages_line_in_the_pdfinfo_output(): void
    {
        $binPath = $this->fakeBinary('pdftotext', self::ARGUMENTS_ECHO);
        $this->fakeBinary('pdfinfo', "#!/bin/sh\nprintf 'Title: Report 2024\\nFile size: 5000 bytes\\n'");

        $this->expectException(DataReaderException::class);
        $this->expectExceptionMessage('Could not determine page count from pdfinfo output.');

        (new PdfReaderWithoutSystemBinaries($binPath))->getPageCount($this->pdf);
    }

    public function test_missing_pdfinfo_binary_is_reported(): void
    {
        $binPath = $this->fakeBinary('pdftotext', self::ARGUMENTS_ECHO);

        $this->expectException(DataReaderException::class);
        $this->expectExceptionMessage('The pdfinfo binary was not found or is not executable.');

        (new PdfReaderWithoutSystemBinaries($binPath))->getPageCount($this->pdf);
    }

    public function test_get_text_extracts_the_real_pdf(): void
    {
        $this->skipIfPdfToTextNotFound();

        $this->assertSame($this->expectedText(), $this->normalizeLineEndings(PdfReader::getText(__DIR__ . '/test.pdf') . PHP_EOL));
    }

    public function test_get_text_extracts_the_real_pdf_with_an_image(): void
    {
        $this->skipIfPdfToTextNotFound();

        $this->assertSame($this->expectedText(), $this->normalizeLineEndings(PdfReader::getText(__DIR__ . '/test-with-image.pdf') . PHP_EOL));
    }

    public function test_get_page_count_of_the_real_pdf(): void
    {
        $this->skipIfPdfToTextNotFound();

        $this->assertSame(1, (new PdfReader())->getPageCount(__DIR__ . '/test.pdf'));
    }

    protected function fakeBinary(string $name, string $script): string
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('Fake poppler binaries are POSIX shell scripts.');
        }

        $path = $this->sandbox . '/bin/' . $name;
        if (!file_exists($this->sandbox . '/bin')) {
            mkdir($this->sandbox . '/bin');
        }
        file_put_contents($path, $script . "\n");
        chmod($path, 0o755);

        return $path;
    }

    protected function skipIfPdfToTextNotFound(): void
    {
        foreach (['/usr/bin', '/usr/local/bin', '/opt/homebrew/bin', '/opt/local/bin'] as $directory) {
            if (is_executable($directory . '/pdftotext')) {
                return;
            }
        }

        $this->markTestSkipped('The pdftotext binary was not found on this machine.');
    }

    protected function expectedText(): string
    {
        return $this->normalizeLineEndings(file_get_contents(__DIR__ . '/target.txt'));
    }

    protected function normalizeLineEndings(string $content): string
    {
        return str_replace("\r\n", "\n", $content);
    }
}
