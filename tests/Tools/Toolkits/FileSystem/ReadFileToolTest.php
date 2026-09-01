<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools\Toolkits\FileSystem;

use NeuronAI\Tools\Toolkits\FileSystem\ReadFileTool;
use NeuronAI\Tools\ToolPropertyInterface;
use PHPUnit\Framework\TestCase;

use function array_map;
use function file_put_contents;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;
use function str_repeat;

class ReadFileToolTest extends TestCase
{
    private ReadFileTool $tool;

    protected function setUp(): void
    {
        $this->tool = new ReadFileTool();
    }

    public function test_read_non_existent_file(): void
    {
        $result = ($this->tool)('/non/existent/file.txt');

        $this->assertStringStartsWith("Error: File '/non/existent/file.txt' does not exist.", $result);
    }

    public function test_read_empty_file(): void
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'neuron_test_');
        file_put_contents($tempFile, '');

        $result = ($this->tool)($tempFile);
        unlink($tempFile);

        $this->assertStringContainsString('[File read successfully: 0 characters]', $result);
    }

    public function test_read_simple_text_file(): void
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'neuron_test_');
        $content = 'Hello, World!';
        file_put_contents($tempFile, $content);

        $result = ($this->tool)($tempFile);
        unlink($tempFile);

        $this->assertStringStartsWith($content, $result);
        $this->assertStringContainsString('[File read successfully: 13 characters]', $result);
    }

    public function test_read_multiline_file(): void
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'neuron_test_');
        $content = "Line 1\nLine 2\nLine 3\nLine 4";
        file_put_contents($tempFile, $content);

        $result = ($this->tool)($tempFile);
        unlink($tempFile);

        $this->assertStringContainsString('Line 1', $result);
        $this->assertStringContainsString('Line 2', $result);
        $this->assertStringContainsString('Line 3', $result);
        $this->assertStringContainsString('Line 4', $result);
    }

    public function test_read_file_with_special_characters(): void
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'neuron_test_');
        $content = "Special chars: äöü ñ é @#$%^&*()";
        file_put_contents($tempFile, $content);

        $result = ($this->tool)($tempFile);
        unlink($tempFile);

        $this->assertStringContainsString('Special chars: äöü ñ é', $result);
    }

    public function test_read_large_file(): void
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'neuron_test_');
        $content = str_repeat('A', 10000);
        file_put_contents($tempFile, $content);

        $result = ($this->tool)($tempFile);
        unlink($tempFile);

        $this->assertStringContainsString('[File read successfully: 10000 characters]', $result);
    }

    public function test_read_file_with_unicode(): void
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'neuron_test_');
        $content = 'Unicode: 你好世界 مرحبا بالعالم 🌍';
        file_put_contents($tempFile, $content);

        $result = ($this->tool)($tempFile);
        unlink($tempFile);

        $this->assertStringContainsString('Unicode: 你好世界', $result);
        $this->assertStringContainsString('مرحبا بالعالم', $result);
        $this->assertStringContainsString('🌍', $result);
    }

    public function test_tool_properties(): void
    {
        $this->assertEquals('read_file', $this->tool->getName());
        $this->assertEquals('Read the contents of a text file.', $this->tool->getDescription());

        $properties = $this->tool->getProperties();
        $this->assertCount(1, $properties);

        $propertyNames = array_map(fn (ToolPropertyInterface $prop): string => $prop->getName(), $properties);
        $this->assertContains('file_path', $propertyNames);
    }
}
