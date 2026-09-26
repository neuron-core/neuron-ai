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
use function ob_get_clean;
use function ob_start;
use function realpath;
use function rewind;
use function rmdir;
use function stream_get_contents;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

class MakeCommandComposerPsr4ListTest extends TestCase
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

    public function test_generates_class_in_first_directory_of_a_multi_directory_psr4_prefix(): void
    {
        file_put_contents($this->workDir . '/composer.json', json_encode(['autoload' => ['psr-4' => ['App\\' => ['src/', 'lib/']]]]));

        [$exitCode, $errors] = $this->runCli(['neuron', 'make:agent', 'App\\Agents\\MyAgent']);

        $this->assertSame('', $errors);
        $this->assertSame(0, $exitCode);
        $this->assertFileExists($this->workDir . '/src/Agents/MyAgent.php');
    }

    public function test_lists_multi_directory_prefixes_without_array_conversion_when_namespace_is_unknown(): void
    {
        file_put_contents($this->workDir . '/composer.json', json_encode(['autoload' => ['psr-4' => ['App\\' => ['src/', 'lib/']]]]));

        [$exitCode, $errors, $output] = $this->runCli(['neuron', 'make:agent', 'Other\\MyAgent']);

        $this->assertSame(0, $exitCode);
        $this->assertStringNotContainsString('Array', $output);
        $this->assertStringContainsString('App\\ -> src/, lib/', $output);
        $this->assertStringNotContainsString('Error:', $errors);
    }

    /**
     * @param array<string> $args
     * @return array{0: int, 1: string, 2: string}
     */
    protected function runCli(array $args): array
    {
        $cli = new NeuronCli();
        /** @var resource $stream */
        $stream = fopen('php://memory', 'r+');
        $cli->setErrorStream($stream);

        ob_start();
        $exitCode = $cli->run($args);
        $output = (string) ob_get_clean();

        rewind($stream);
        return [$exitCode, (string) stream_get_contents($stream), $output];
    }
}
