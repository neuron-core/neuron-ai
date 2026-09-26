<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools\Toolkits\FileSystem;

use NeuronAI\Tests\Support\FileSystemSandbox;
use NeuronAI\Tools\Toolkits\FileSystem\GlobPathTool;
use PHPUnit\Framework\TestCase;

use function file_put_contents;
use function mkdir;

class GlobPathToolSymlinkLoopTest extends TestCase
{
    use FileSystemSandbox;

    public function test_recursive_glob_visits_a_symlinked_ancestor_only_once(): void
    {
        $scope = $this->createSandbox('neuron_glob_loop');
        mkdir($scope . '/sub');
        file_put_contents($scope . '/notes.txt', 'x');
        $this->symlinkOrSkip($scope, $scope . '/sub/loop');

        try {
            $result = (new GlobPathTool($scope))('.', '**/notes.txt');

            $this->assertStringStartsWith('Found 1 match(es)', $result);
        } finally {
            $this->removeSandbox($scope);
        }
    }
}
