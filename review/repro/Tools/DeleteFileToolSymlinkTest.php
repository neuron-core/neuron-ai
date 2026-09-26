<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools\Toolkits\FileSystem;

use NeuronAI\Tests\Support\FileSystemSandbox;
use NeuronAI\Tools\Toolkits\FileSystem\DeleteFileTool;
use PHPUnit\Framework\TestCase;

use function file_put_contents;
use function is_link;

class DeleteFileToolSymlinkTest extends TestCase
{
    use FileSystemSandbox;

    public function test_deleting_a_symlink_under_a_scope_removes_the_link_not_its_target(): void
    {
        $scope = $this->createSandbox('neuron_delete_link');
        file_put_contents($scope . '/notes.txt', 'keep me');
        $this->symlinkOrSkip($scope . '/notes.txt', $scope . '/alias.txt');

        try {
            $result = (new DeleteFileTool($scope))('alias.txt');

            $this->assertSame('success', $result['status'] ?? null);
            $this->assertFalse(is_link($scope . '/alias.txt'));
            $this->assertFileExists($scope . '/notes.txt');
        } finally {
            $this->removeSandbox($scope);
        }
    }

    public function test_deleting_a_symlink_without_a_scope_removes_the_link_not_its_target(): void
    {
        $dir = $this->createSandbox('neuron_delete_link_unscoped');
        file_put_contents($dir . '/notes.txt', 'keep me');
        $this->symlinkOrSkip($dir . '/notes.txt', $dir . '/alias.txt');

        try {
            (new DeleteFileTool())($dir . '/alias.txt');

            $this->assertFalse(is_link($dir . '/alias.txt'));
            $this->assertFileExists($dir . '/notes.txt');
        } finally {
            $this->removeSandbox($dir);
        }
    }
}
