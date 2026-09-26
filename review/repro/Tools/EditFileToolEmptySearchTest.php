<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools\Toolkits\FileSystem;

use NeuronAI\Tests\Support\FileSystemSandbox;
use NeuronAI\Tools\Toolkits\FileSystem\EditFileTool;
use NeuronAI\Tools\ToolOutput;
use PHPUnit\Framework\TestCase;

use function file_get_contents;
use function file_put_contents;

class EditFileToolEmptySearchTest extends TestCase
{
    use FileSystemSandbox;

    public function test_empty_search_string_is_refused_instead_of_reporting_an_edit(): void
    {
        $dir = $this->createSandbox('neuron_edit_empty');
        file_put_contents($dir . '/file.txt', 'content');

        try {
            $result = (new EditFileTool($dir))('file.txt', '', 'new');

            $this->assertInstanceOf(ToolOutput::class, $result);
            $this->assertTrue($result->isError());
            $this->assertSame('content', file_get_contents($dir . '/file.txt'));
        } finally {
            $this->removeSandbox($dir);
        }
    }
}
