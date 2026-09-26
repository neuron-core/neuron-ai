<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools\Toolkits\FileSystem;

use NeuronAI\Tests\Support\FileSystemSandbox;
use NeuronAI\Tools\Toolkits\FileSystem\GrepFileContentTool;
use NeuronAI\Tools\Toolkits\FileSystem\ReadFileTool;
use NeuronAI\Tools\ToolOutput;
use PHPUnit\Framework\TestCase;

use function file_put_contents;
use function json_encode;
use function mb_check_encoding;

class ReadFileBinaryContentTest extends TestCase
{
    use FileSystemSandbox;

    protected string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = $this->createSandbox('neuron_read_binary');
        file_put_contents($this->tempDir . '/image.png', "\x89PNG\r\n\x1a\n\x00\x00\x00\rIHDR\xff\xfe\xfd");
    }

    protected function tearDown(): void
    {
        $this->removeSandbox($this->tempDir);
    }

    public function test_reading_a_binary_file_yields_a_result_that_can_be_sent_to_a_provider(): void
    {
        $result = (new ReadFileTool($this->tempDir))('image.png');

        $this->assertResultIsTransportable($result);
    }

    public function test_grep_on_a_binary_file_yields_a_result_that_can_be_sent_to_a_provider(): void
    {
        $result = (new GrepFileContentTool($this->tempDir))('image.png', '/IHDR.+/s');

        $this->assertResultIsTransportable($result);
    }

    protected function assertResultIsTransportable(string|ToolOutput $result): void
    {
        if ($result instanceof ToolOutput) {
            $this->assertTrue($result->isError());
            return;
        }

        $this->assertTrue(mb_check_encoding($result, 'UTF-8'), 'Tool result is not valid UTF-8.');
        // Mirrors the default CurlHttpClient, which sends json_encode($body) unchecked.
        $this->assertNotFalse(json_encode(['type' => 'tool_result', 'content' => $result]));
    }
}
