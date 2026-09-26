<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools\Toolkits\FileSystem;

use NeuronAI\Tests\Support\FileSystemSandbox;
use NeuronAI\Tools\Toolkits\FileSystem\GrepFileContentTool;
use PHPUnit\Framework\TestCase;

use function file_put_contents;

class GrepFileContentToolMultibyteLineTest extends TestCase
{
    use FileSystemSandbox;

    public function test_line_number_is_correct_after_multibyte_lines(): void
    {
        $dir = $this->createSandbox('neuron_grep_mb');
        file_put_contents($dir . '/file.txt', "éééééé\nfoo\nbar");

        try {
            $this->assertStringContainsString('Match 1 (line 2): foo', (new GrepFileContentTool())($dir . '/file.txt', '/foo/'));
        } finally {
            $this->removeSandbox($dir);
        }
    }

    public function test_match_on_last_line_after_multibyte_content_is_not_line_zero(): void
    {
        $dir = $this->createSandbox('neuron_grep_mb');
        file_put_contents($dir . '/file.txt', "日本語日本語\nfoo");

        try {
            $this->assertStringContainsString('Match 1 (line 2): foo', (new GrepFileContentTool())($dir . '/file.txt', '/foo/'));
        } finally {
            $this->removeSandbox($dir);
        }
    }
}
