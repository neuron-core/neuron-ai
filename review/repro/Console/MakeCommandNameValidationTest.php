<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Console\Make;

use FilesystemIterator;
use NeuronAI\Console\NeuronCli;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

use function chdir;
use function file_put_contents;
use function fopen;
use function getcwd;
use function glob;
use function json_encode;
use function mkdir;
use function ob_end_clean;
use function ob_start;
use function realpath;
use function rmdir;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

use const GLOB_BRACE;

class MakeCommandNameValidationTest extends TestCase
{
    protected string $root;
    protected string $project;
    protected string $previousCwd;

    protected function setUp(): void
    {
        $this->previousCwd = (string) getcwd();
        $this->root = sys_get_temp_dir() . '/neuron-make-repro-' . uniqid();
        $this->project = $this->root . '/nested/project';
        mkdir($this->project . '/src', 0o755, true);
        $this->root = (string) realpath($this->root);
        $this->project = (string) realpath($this->project);
        file_put_contents($this->project . '/composer.json', json_encode(['autoload' => ['psr-4' => ['App\\' => 'src/']]]));
        chdir($this->project);
    }

    protected function tearDown(): void
    {
        chdir($this->previousCwd);
        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() && !$item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->root);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidNames(): iterable
    {
        yield 'namespace traversal' => ['App\\..\\..\\..\\Escaped'];
        yield 'slash traversal' => ['../../Escaped'];
        yield 'absolute path' => ['/tmp/Escaped'];
        yield 'leading digit' => ['App\\Agents\\123Agent'];
        yield 'leading backslash' => ['\\App\\Agents\\MyAgent'];
        yield 'trailing backslash' => ['App\\Agents\\'];
        yield 'reserved word' => ['App\\Agents\\Class'];
        yield 'code injection' => ['App\\Agents\\Foo{}echo(1);class Bar'];
    }

    #[DataProvider('invalidNames')]
    public function test_rejects_names_that_are_not_valid_class_names(string $name): void
    {
        $cli = new NeuronCli();
        /** @var resource $stream */
        $stream = fopen('php://memory', 'r+');
        $cli->setErrorStream($stream);

        ob_start();
        $exitCode = $cli->run(['neuron', 'make:agent', $name]);
        ob_end_clean();

        $this->assertSame(1, $exitCode, "make:agent accepted the invalid class name '{$name}'.");
        $this->assertSame([], glob($this->root . '/{,*/,*/*/,*/*/*/,*/*/*/*/}*.php', GLOB_BRACE));
    }

    public function test_still_creates_valid_class(): void
    {
        $cli = new NeuronCli();
        /** @var resource $stream */
        $stream = fopen('php://memory', 'r+');
        $cli->setErrorStream($stream);

        ob_start();
        $exitCode = $cli->run(['neuron', 'make:agent', 'App\\Agents\\MyAgent']);
        ob_end_clean();

        $this->assertSame(0, $exitCode);
        $this->assertFileExists($this->project . '/src/Agents/MyAgent.php');
    }
}
