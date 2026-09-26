<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Console\Make;

use NeuronAI\Agent\Agent;
use NeuronAI\Console\NeuronCli;
use NeuronAI\Evaluation\BaseEvaluator;
use NeuronAI\RAG\RAG;
use NeuronAI\Tools\Tool;
use NeuronAI\Workflow\Events\Event;
use NeuronAI\Workflow\Middleware\WorkflowMiddleware;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\Workflow;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

use function chdir;
use function dirname;
use function escapeshellarg;
use function exec;
use function file_get_contents;
use function file_put_contents;
use function getcwd;
use function implode;
use function json_encode;
use function mkdir;
use function ob_get_clean;
use function ob_start;
use function realpath;
use function rmdir;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;
use function fopen;
use function rewind;
use function stream_get_contents;
use function var_export;

use const PHP_BINARY;
use const PHP_EOL;

class MakeCommandTest extends TestCase
{
    protected string $workDir;
    protected string $previousCwd;

    /** @var resource */
    protected mixed $errorStream;

    protected function setUp(): void
    {
        $this->previousCwd = (string) getcwd();
        $this->workDir = sys_get_temp_dir() . '/neuron-make-' . uniqid();
        mkdir($this->workDir, 0o755, true);
        // getcwd() resolves symlinks (e.g. macOS /tmp), so compare against the real path.
        $this->workDir = (string) realpath($this->workDir);
        $this->writeComposerAutoload(['App\\' => 'src/']);
        chdir($this->workDir);

        /** @var resource $stream */
        $stream = fopen('php://memory', 'r+');
        $this->errorStream = $stream;
    }

    protected function tearDown(): void
    {
        chdir($this->previousCwd);
        $this->removeDirectory($this->workDir);
    }

    public function test_creates_class_in_psr4_path(): void
    {
        [$exitCode, $output] = $this->make('make:agent', 'App\\Agents\\MyAgent');

        $file = $this->workDir . '/src/Agents/MyAgent.php';
        $this->assertSame(0, $exitCode);
        $this->assertSame("Success: Created Agent: {$file}" . PHP_EOL, $output);
        $this->assertSame('', $this->errors());

        $content = (string) file_get_contents($file);
        $this->assertStringContainsString("\nnamespace App\\Agents;\n", $content);
        $this->assertStringContainsString("\nclass MyAgent extends Agent\n", $content);
    }

    public function test_uses_the_first_psr4_namespace_when_none_given(): void
    {
        $this->writeComposerAutoload(['Acme\\Bots\\' => 'src/', 'App\\' => 'app/']);

        [$exitCode, $output] = $this->make('make:tool', 'MyTool');

        $file = $this->workDir . '/src/MyTool.php';
        $this->assertSame(0, $exitCode);
        $this->assertSame("Success: Created Tool: {$file}" . PHP_EOL, $output);
        $this->assertStringContainsString("\nnamespace Acme\\Bots;\n", (string) file_get_contents($file));
    }

    /**
     * @return iterable<string, array{string, class-string, string}>
     */
    public static function generators(): iterable
    {
        yield 'make:agent' => ['make:agent', Agent::class, 'class Example extends Agent'];
        yield 'make:middleware' => ['make:middleware', WorkflowMiddleware::class, 'class Example implements WorkflowMiddleware'];
        yield 'make:node' => ['make:node', Node::class, 'class Example extends Node'];
        yield 'make:tool' => ['make:tool', Tool::class, 'class Example extends Tool'];
        yield 'make:rag' => ['make:rag', RAG::class, 'class Example extends RAG'];
        yield 'make:workflow' => ['make:workflow', Workflow::class, 'class Example extends Workflow'];
        yield 'make:event' => ['make:event', Event::class, 'class Example implements Event'];
        yield 'make:evaluators' => ['make:evaluators', BaseEvaluator::class, 'class Example extends BaseEvaluator'];
    }

    /**
     * Loading the class catches what a syntax check cannot: a stub drifting from the
     * framework contract it extends (renamed imports, incompatible signatures).
     *
     * @param class-string $contract
     */
    #[DataProvider('generators')]
    public function test_generates_a_class_that_loads_against_the_framework(string $command, string $contract, string $declaration): void
    {
        [$exitCode] = $this->make($command, 'App\\Generated\\Example');

        $this->assertSame(0, $exitCode);

        $file = $this->workDir . '/src/Generated/Example.php';
        $content = (string) file_get_contents($file);
        $this->assertStringContainsString("\n{$declaration}\n", $content);
        $this->assertStringNotContainsString('[namespace]', $content);
        $this->assertStringNotContainsString('[classname]', $content);

        $this->assertSame(['loaded'], $this->loadInSeparateProcess($file, 'App\\Generated\\Example', $contract));
    }

    public function test_tool_name_defaults_to_the_class_name(): void
    {
        $this->make('make:tool', 'App\\Tools\\SearchWeb');

        $this->assertStringContainsString(
            "protected string \$name = 'SearchWeb';",
            (string) file_get_contents($this->workDir . '/src/Tools/SearchWeb.php')
        );
    }

    public function test_fails_when_file_already_exists(): void
    {
        mkdir($this->workDir . '/src/Agents', 0o755, true);
        $file = $this->workDir . '/src/Agents/MyAgent.php';
        file_put_contents($file, 'existing');

        [$exitCode, $output] = $this->make('make:agent', 'App\\Agents\\MyAgent');

        $this->assertSame(1, $exitCode);
        $this->assertSame('existing', (string) file_get_contents($file));
        $this->assertSame("Error: File already exists: {$file}" . PHP_EOL, $this->errors());
        $this->assertStringNotContainsString('Success', $output);
    }

    public function test_fails_without_class_name(): void
    {
        [$exitCode, $output] = $this->make('make:agent');

        $this->assertSame(1, $exitCode);
        $this->assertSame('Error: Class name argument is required' . PHP_EOL, $this->errors());
        $this->assertStringContainsString('Usage: neuron make:agent [namespace\\]ClassName', $output);
        $this->assertDirectoryDoesNotExist($this->workDir . '/src');
    }

    public function test_help_prints_usage_without_generating(): void
    {
        [$exitCode, $output] = $this->make('make:node', 'App\\Nodes\\MyNode', '--help');

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Create a new Node', $output);
        $this->assertStringContainsString('neuron make:node MyApp\\Services\\MyClass', $output);
        $this->assertDirectoryDoesNotExist($this->workDir . '/src');
    }

    public function test_options_are_never_taken_as_the_class_name(): void
    {
        [$exitCode] = $this->make('make:agent', '--force', '-q', 'App\\Agents\\MyAgent');

        $this->assertSame(0, $exitCode);
        $this->assertFileExists($this->workDir . '/src/Agents/MyAgent.php');
    }

    public function test_only_the_first_name_is_generated(): void
    {
        $this->make('make:agent', 'App\\First', 'App\\Second');

        $this->assertFileExists($this->workDir . '/src/First.php');
        $this->assertFileDoesNotExist($this->workDir . '/src/Second.php');
    }

    public function test_namespace_outside_psr4_warns_and_falls_back_to_the_namespace_path(): void
    {
        [$exitCode, $output] = $this->make('make:agent', 'Other\\Agents\\MyAgent');

        $file = $this->workDir . '/Other/Agents/MyAgent.php';
        $this->assertSame(0, $exitCode);
        $this->assertSame(implode(PHP_EOL, [
            "Warning: Namespace 'Other\\Agents' doesn't match any PSR-4 configuration in composer.json",
            'Available PSR-4 namespaces:',
            '  App\\ -> src/',
            '',
            "Success: Created Agent: {$file}",
            '',
        ]), $output);
        $this->assertStringContainsString("\nnamespace Other\\Agents;\n", (string) file_get_contents($file));
    }

    public function test_psr4_prefix_matches_whole_namespace_segments_only(): void
    {
        [, $output] = $this->make('make:agent', 'Application\\MyAgent');

        $this->assertStringContainsString("Warning: Namespace 'Application' doesn't match", $output);
        $this->assertFileExists($this->workDir . '/Application/MyAgent.php');
        $this->assertDirectoryDoesNotExist($this->workDir . '/src');
    }

    public function test_maps_each_psr4_prefix_to_its_own_directory(): void
    {
        $this->writeComposerAutoload(['App\\' => 'src/', 'Lib\\' => 'lib']);

        [$exitCode] = $this->make('make:tool', 'Lib\\Tools\\Search');

        $this->assertSame(0, $exitCode);
        $this->assertFileExists($this->workDir . '/lib/Tools/Search.php');
    }

    /**
     * @return iterable<string, array{string|null}>
     */
    public static function unusableComposerFiles(): iterable
    {
        yield 'no composer.json' => [null];
        yield 'invalid json' => ['{not json'];
        yield 'no psr-4 section' => ['{"autoload":{"classmap":["src/"]}}'];
    }

    #[DataProvider('unusableComposerFiles')]
    public function test_without_psr4_configuration_defaults_to_the_app_namespace(?string $composer): void
    {
        unlink($this->workDir . '/composer.json');
        if ($composer !== null) {
            file_put_contents($this->workDir . '/composer.json', $composer);
        }

        [$exitCode, $output] = $this->make('make:tool', 'MyTool');

        $file = $this->workDir . '/App/MyTool.php';
        $this->assertSame(0, $exitCode);
        $this->assertSame(
            "Warning: Namespace 'App' doesn't match any PSR-4 configuration in composer.json" . PHP_EOL
            . "Success: Created Tool: {$file}" . PHP_EOL,
            $output
        );
        $this->assertStringContainsString("\nnamespace App;\n", (string) file_get_contents($file));
    }

    /**
     * @param array<string, string> $psr4
     */
    protected function writeComposerAutoload(array $psr4): void
    {
        file_put_contents($this->workDir . '/composer.json', json_encode(['autoload' => ['psr-4' => $psr4]]));
    }

    /**
     * @return array{int, string} The exit code and the standard output.
     */
    protected function make(string ...$args): array
    {
        $cli = new NeuronCli();
        $cli->setErrorStream($this->errorStream);

        ob_start();
        $exitCode = $cli->run(['neuron', ...$args]);

        return [$exitCode, (string) ob_get_clean()];
    }

    protected function errors(): string
    {
        rewind($this->errorStream);
        return (string) stream_get_contents($this->errorStream);
    }

    /**
     * A fatal error while loading must not take the test runner down with it.
     *
     * @param class-string $contract
     * @return string[]
     */
    protected function loadInSeparateProcess(string $file, string $class, string $contract): array
    {
        $autoload = dirname(__DIR__, 3) . '/vendor/autoload.php';
        $code = 'require ' . var_export($autoload, true) . '; require ' . var_export($file, true) . ';'
            . ' echo is_a(' . var_export($class, true) . ', ' . var_export($contract, true) . ', true) ? "loaded" : "wrong contract";';

        exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($code) . ' 2>&1', $output);

        return $output;
    }

    protected function removeDirectory(string $directory): void
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
