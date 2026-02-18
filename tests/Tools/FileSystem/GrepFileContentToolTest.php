<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools\FileSystem;

use NeuronAI\Tools\Toolkits\FileSystem\GrepFileContentTool;
use NeuronAI\Tools\ToolPropertyInterface;
use PHPUnit\Framework\TestCase;

use function array_map;
use function file_put_contents;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;
use function str_repeat;

class GrepFileContentToolTest extends TestCase
{
    private GrepFileContentTool $tool;

    protected function setUp(): void
    {
        $this->tool = new GrepFileContentTool();
    }

    public function testGrepNonExistentFile(): void
    {
        $result = ($this->tool)('/non/existent/file.txt', 'pattern');

        $this->assertStringStartsWith("Error: File '/non/existent/file.txt' does not exist.", $result);
    }

    public function testGrepNoMatches(): void
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'neuron_test_');
        file_put_contents($tempFile, "Hello\nWorld\nTest");

        $result = ($this->tool)($tempFile, '/notfound/');
        unlink($tempFile);

        $this->assertStringContainsString("No matches found for pattern '/notfound/'", $result);
    }

    public function testGrepSimplePattern(): void
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'neuron_test_');
        file_put_contents($tempFile, "Hello World\nHello World\nTest");

        $result = ($this->tool)($tempFile, '/Hello/');
        unlink($tempFile);

        $this->assertStringContainsString("Found 2 match(es) for pattern '/Hello/'", $result);
        $this->assertStringContainsString('Match 1 (line 1): Hello', $result);
        $this->assertStringContainsString('Match 2 (line 2): Hello', $result);
    }

    public function testGrepWithRegexPattern(): void
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'neuron_test_');
        file_put_contents($tempFile, "test@example.com\nadmin@test.org\ninfo@example.com");

        $result = ($this->tool)($tempFile, '/[\w.]+@[\w.]+/');
        unlink($tempFile);

        $this->assertStringContainsString('Found 3 match(es)', $result);
        $this->assertStringContainsString('test@example.com', $result);
        $this->assertStringContainsString('admin@test.org', $result);
    }

    public function testGrepCaseSensitive(): void
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'neuron_test_');
        file_put_contents($tempFile, "Hello\nhello\nHELLO");

        $result = ($this->tool)($tempFile, '/Hello/');
        unlink($tempFile);

        $this->assertStringContainsString('Found 1 match(es)', $result);
        $this->assertStringContainsString('Match 1 (line 1): Hello', $result);
    }

    public function testGrepCaseInsensitive(): void
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'neuron_test_');
        file_put_contents($tempFile, "Hello\nhello\nHELLO");

        $result = ($this->tool)($tempFile, '/hello/i');
        unlink($tempFile);

        $this->assertStringContainsString('Found 3 match(es)', $result);
    }

    public function testGrepNumbers(): void
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'neuron_test_');
        file_put_contents($tempFile, "Count: 42\nCount: 100\nCount: 999");

        $result = ($this->tool)($tempFile, '/\d+/');
        unlink($tempFile);

        $this->assertStringContainsString('Found 3 match(es)', $result);
    }

    public function testGrepLongMatchTruncates(): void
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'neuron_test_');
        $longMatch = str_repeat('A', 200);
        file_put_contents($tempFile, $longMatch);

        $result = ($this->tool)($tempFile, '/A+/');
        unlink($tempFile);

        $this->assertStringContainsString('Found 1 match(es)', $result);
        // Match should be truncated to ~100 characters
        $this->assertMatchesRegularExpression('/AAA\.\.\./', $result);
    }

    public function testGrepInvalidRegex(): void
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'neuron_test_');
        file_put_contents($tempFile, "Test content");

        $result = ($this->tool)($tempFile, '/[invalid(');
        unlink($tempFile);

        $this->assertStringStartsWith('Error:', $result);
        $this->assertStringContainsString("Invalid regex pattern '/[invalid('", $result);
    }

    public function testGrepWithSpecialCharacters(): void
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'neuron_test_');
        file_put_contents($tempFile, "Price: $100\nPrice: €50\nPrice: ¥500");

        $result = ($this->tool)($tempFile, '/Price: .+/');
        unlink($tempFile);

        $this->assertStringContainsString('Found 3 match(es)', $result);
        $this->assertStringContainsString('Price: $', $result);
        $this->assertStringContainsString('Price: €', $result);
        $this->assertStringContainsString('Price: ¥', $result);
    }

    public function testToolProperties(): void
    {
        $this->assertEquals('grep_file_content', $this->tool->getName());
        $this->assertEquals('Search for a regex pattern in a file.', $this->tool->getDescription());

        $properties = $this->tool->getProperties();
        $this->assertCount(2, $properties);

        $propertyNames = array_map(fn (ToolPropertyInterface $prop): string => $prop->getName(), $properties);
        $this->assertContains('file_path', $propertyNames);
        $this->assertContains('pattern', $propertyNames);
    }
}
