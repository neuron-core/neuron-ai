<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools\Toolkits\FileSystem;

use NeuronAI\Tests\Support\FileSystemSandbox;
use NeuronAI\Tools\Toolkits\FileSystem\GlobPathTool;
use PHPUnit\Framework\TestCase;

use function file_put_contents;
use function mkdir;

class GlobPathToolInnerGlobstarTest extends TestCase
{
    use FileSystemSandbox;

    public function test_globstar_after_a_directory_prefix_matches_every_depth(): void
    {
        $scope = $this->createSandbox('neuron_glob_globstar');
        mkdir($scope . '/src/a/b', 0o755, true);
        file_put_contents($scope . '/src/x.php', '');
        file_put_contents($scope . '/src/a/y.php', '');
        file_put_contents($scope . '/src/a/b/z.php', '');

        try {
            $this->assertSame(
                "Found 3 match(es) for pattern 'src/**/*.php' in directory '.':\n\n"
                . "  - src/a/b/z.php\n"
                . "  - src/a/y.php\n"
                . "  - src/x.php\n",
                (new GlobPathTool($scope))('.', 'src/**/*.php')
            );
        } finally {
            $this->removeSandbox($scope);
        }
    }
}
