<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Console;

use NeuronAI\Console\NeuronCli;
use PHPUnit\Framework\TestCase;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

use function chdir;
use function file_get_contents;
use function file_put_contents;
use function getcwd;
use function json_encode;
use function mkdir;
use function ob_end_clean;
use function ob_start;
use function rmdir;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;
use function fopen;
use function rewind;
use function stream_get_contents;

class MakeCommandTest extends TestCase
{
    private string $workDir;
    private string $previousCwd;

    protected function setUp(): void
    {
        $this->previousCwd = (string) getcwd();
        $this->workDir = sys_get_temp_dir() . '/neuron-make-' . uniqid();
        mkdir($this->workDir, 0o755, true);
        file_put_contents(
            $this->workDir . '/composer.json',
            json_encode(['autoload' => ['psr-4' => ['App\\' => 'src/']]])
        );
        chdir($this->workDir);
    }

    protected function tearDown(): void
    {
        chdir($this->previousCwd);
        $this->removeDirectory($this->workDir);
    }

    public function testCreatesClassInPsr4Path(): void
    {
        ob_start();
        $exitCode = (new NeuronCli())->run(['neuron', 'make:agent', 'App\\Agents\\MyAgent']);
        ob_end_clean();

        $this->assertSame(0, $exitCode);

        $file = $this->workDir . '/src/Agents/MyAgent.php';
        $this->assertFileExists($file);

        $content = (string) file_get_contents($file);
        $this->assertStringContainsString('namespace App\\Agents;', $content);
        $this->assertStringContainsString('class MyAgent extends Agent', $content);
    }

    public function testUsesDefaultNamespaceWhenNoneGiven(): void
    {
        ob_start();
        $exitCode = (new NeuronCli())->run(['neuron', 'make:tool', 'MyTool']);
        ob_end_clean();

        $this->assertSame(0, $exitCode);

        $file = $this->workDir . '/src/MyTool.php';
        $this->assertFileExists($file);
        $this->assertStringContainsString('namespace App;', (string) file_get_contents($file));
    }

    public function testFailsWhenFileAlreadyExists(): void
    {
        mkdir($this->workDir . '/src/Agents', 0o755, true);
        file_put_contents($this->workDir . '/src/Agents/MyAgent.php', 'existing');

        $cli = new NeuronCli();
        $stream = $this->captureErrorStream($cli);

        ob_start();
        $exitCode = $cli->run(['neuron', 'make:agent', 'App\\Agents\\MyAgent']);
        ob_end_clean();

        $this->assertSame(1, $exitCode);
        $this->assertSame('existing', (string) file_get_contents($this->workDir . '/src/Agents/MyAgent.php'));
        $this->assertStringContainsString('File already exists', $this->readStream($stream));
    }

    public function testFailsWithoutClassName(): void
    {
        $cli = new NeuronCli();
        $stream = $this->captureErrorStream($cli);

        ob_start();
        $exitCode = $cli->run(['neuron', 'make:agent']);
        ob_end_clean();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Class name argument is required', $this->readStream($stream));
    }

    /**
     * @return resource
     */
    private function captureErrorStream(NeuronCli $cli): mixed
    {
        /** @var resource $stream */
        $stream = fopen('php://memory', 'r+');
        $cli->setErrorStream($stream);
        return $stream;
    }

    /**
     * @param resource $stream
     */
    private function readStream(mixed $stream): string
    {
        rewind($stream);
        return (string) stream_get_contents($stream);
    }

    private function removeDirectory(string $directory): void
    {
        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($directory);
    }
}
