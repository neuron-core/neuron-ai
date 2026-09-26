<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Console\Make;

use FilesystemIterator;
use NeuronAI\Console\NeuronCli;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

use function chdir;
use function file_put_contents;
use function fopen;
use function getcwd;
use function json_encode;
use function mkdir;
use function ob_end_clean;
use function ob_start;
use function realpath;
use function rmdir;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

class MakeCommandMostSpecificPrefixTest extends TestCase
{
    protected string $workDir;
    protected string $previousCwd;

    protected function setUp(): void
    {
        $this->previousCwd = (string) getcwd();
        $this->workDir = sys_get_temp_dir() . '/neuron-make-psr4-' . uniqid();
        mkdir($this->workDir, 0o755, true);
        $this->workDir = (string) realpath($this->workDir);
        chdir($this->workDir);
    }

    protected function tearDown(): void
    {
        chdir($this->previousCwd);
        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->workDir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->workDir);
    }

    public function test_the_most_specific_psr4_prefix_wins(): void
    {
        file_put_contents($this->workDir . '/composer.json', json_encode(['autoload' => ['psr-4' => [
            'App\\' => 'src/',
            'App\\Tests\\' => 'tests/',
        ]]]));

        $cli = new NeuronCli();
        /** @var resource $stream */
        $stream = fopen('php://memory', 'r+');
        $cli->setErrorStream($stream);

        ob_start();
        $exitCode = $cli->run(['neuron', 'make:agent', 'App\\Tests\\MyAgent']);
        ob_end_clean();

        $this->assertSame(0, $exitCode);
        $this->assertFileExists($this->workDir . '/tests/MyAgent.php');
        $this->assertFileDoesNotExist($this->workDir . '/src/Tests/MyAgent.php');
    }
}
