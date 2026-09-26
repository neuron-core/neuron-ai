<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Evaluation;

use Closure;
use FilesystemIterator;
use InvalidArgumentException;
use NeuronAI\Evaluation\EvaluatorDiscovery;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

use function basename;
use function bin2hex;
use function dirname;
use function file_put_contents;
use function is_dir;
use function is_file;
use function mkdir;
use function random_bytes;
use function rmdir;
use function spl_autoload_register;
use function spl_autoload_unregister;
use function str_replace;
use function str_starts_with;
use function strlen;
use function substr;
use function sys_get_temp_dir;
use function unlink;

class EvaluatorDiscoveryTest extends TestCase
{
    protected string $directory;

    /**
     * Unique per test: discovered classes are declared for the rest of the process.
     */
    protected string $namespace;

    protected Closure $autoloader;

    protected function setUp(): void
    {
        $suffix = bin2hex(random_bytes(6));
        $this->directory = sys_get_temp_dir() . '/neuron-discovery-' . $suffix;
        $this->namespace = 'EvaluatorDiscoveryFixture' . $suffix;
        mkdir($this->directory);

        // Mirrors a PSR-4 mapping of the fixture namespace to the fixture directory
        $this->autoloader = function (string $class): void {
            if (!str_starts_with($class, $this->namespace . '\\')) {
                return;
            }

            $file = $this->directory . '/' . str_replace('\\', '/', substr($class, strlen($this->namespace) + 1)) . '.php';
            if (is_file($file)) {
                require $file;
            }
        };
        spl_autoload_register($this->autoloader);
    }

    protected function tearDown(): void
    {
        spl_autoload_unregister($this->autoloader);

        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->directory);
    }

    public function test_discovers_concrete_evaluators_recursively(): void
    {
        $this->writeEvaluator('SupportEvaluator');
        $this->writeEvaluator('Nested/Deeper/RefundEvaluator');

        $discovered = (new EvaluatorDiscovery())->discover($this->directory);

        $this->assertEqualsCanonicalizing([
            "{$this->namespace}\\SupportEvaluator",
            "{$this->namespace}\\Nested\\Deeper\\RefundEvaluator",
        ], $discovered);
    }

    public function test_skips_classes_that_are_not_runnable_evaluators(): void
    {
        $this->writeEvaluator('RunnableEvaluator');
        $this->writeClass('AbstractEvaluator', 'abstract class AbstractEvaluator extends \NeuronAI\Evaluation\BaseEvaluator {}');
        $this->writeClass('PlainService', 'class PlainService {}');
        $this->writeClass('EvaluatorContract', 'interface EvaluatorContract extends \NeuronAI\Evaluation\Contracts\EvaluatorInterface {}');
        $this->writeClass(
            'SingletonEvaluator',
            'class SingletonEvaluator extends \NeuronAI\Evaluation\BaseEvaluator {'
            . ' private function __construct() { parent::__construct(); }'
            . $this->evaluatorMethods() . ' }'
        );

        $discovered = (new EvaluatorDiscovery())->discover($this->directory);

        $this->assertSame(["{$this->namespace}\\RunnableEvaluator"], $discovered);
    }

    public function test_ignores_files_without_the_php_extension(): void
    {
        // The declared class is autoloadable, so only the extension check keeps it out
        $this->writeEvaluator('Hidden/ShadowEvaluator');
        mkdir("{$this->directory}/Docs");
        file_put_contents(
            "{$this->directory}/Docs/Notes.txt",
            "<?php\nnamespace {$this->namespace}\\Hidden;\nclass ShadowEvaluator extends \\NeuronAI\\Evaluation\\BaseEvaluator {}\n"
        );

        $this->assertSame([], (new EvaluatorDiscovery())->discover("{$this->directory}/Docs"));
    }

    public function test_namespace_is_read_from_the_declaration_not_from_comments(): void
    {
        file_put_contents(
            "{$this->directory}/CommentedEvaluator.php",
            "<?php\n\n// Moved to this namespace in v2; see UPGRADE.md\n\nnamespace {$this->namespace};\n\n"
            . "class CommentedEvaluator extends \\NeuronAI\\Evaluation\\BaseEvaluator {{$this->evaluatorMethods()} }\n"
        );

        $discovered = (new EvaluatorDiscovery())->discover($this->directory);

        $this->assertSame(["{$this->namespace}\\CommentedEvaluator"], $discovered);
    }

    public function test_never_executes_files_whose_classes_are_not_autoloadable(): void
    {
        $marker = "{$this->directory}/executed.marker";
        file_put_contents(
            "{$this->directory}/Rogue.php",
            "<?php\nnamespace Unmapped{$this->namespace};\n"
            . "file_put_contents('{$marker}', 'executed');\n"
            . "class RogueEvaluator extends \\NeuronAI\\Evaluation\\BaseEvaluator {}\n"
        );

        $discovered = (new EvaluatorDiscovery())->discover($this->directory);

        $this->assertSame([], $discovered);
        $this->assertFileDoesNotExist($marker);
    }

    public function test_file_without_classes_contributes_nothing(): void
    {
        file_put_contents("{$this->directory}/helpers.php", "<?php\n\nfunction helper(): void {}\n");

        $this->assertSame([], (new EvaluatorDiscovery())->discover($this->directory));
    }

    public function test_empty_directory_discovers_nothing(): void
    {
        $this->assertSame([], (new EvaluatorDiscovery())->discover($this->directory));
    }

    public function test_missing_directory_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Directory not found: {$this->directory}/missing");

        (new EvaluatorDiscovery())->discover("{$this->directory}/missing");
    }

    public function test_a_file_path_is_rejected(): void
    {
        $this->writeEvaluator('SingleFileEvaluator');
        $file = "{$this->directory}/SingleFileEvaluator.php";

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Directory not found: {$file}");

        (new EvaluatorDiscovery())->discover($file);
    }

    protected function writeEvaluator(string $relativeClass): void
    {
        $this->writeClass(
            $relativeClass,
            'class ' . basename($relativeClass) . " extends \\NeuronAI\\Evaluation\\BaseEvaluator {" . $this->evaluatorMethods() . ' }'
        );
    }

    protected function writeClass(string $relativeClass, string $declaration): void
    {
        $file = "{$this->directory}/{$relativeClass}.php";
        $subNamespace = str_replace('/', '\\', dirname($relativeClass));
        $namespace = $subNamespace === '.' ? $this->namespace : "{$this->namespace}\\{$subNamespace}";

        if (!is_dir(dirname($file))) {
            mkdir(dirname($file), 0o777, true);
        }

        file_put_contents($file, "<?php\n\nnamespace {$namespace};\n\n{$declaration}\n");
    }

    protected function evaluatorMethods(): string
    {
        return ' public function getDataset(): \NeuronAI\Evaluation\Contracts\DatasetInterface'
            . ' { return new \NeuronAI\Evaluation\Dataset\ArrayDataset([]); }'
            . ' public function run(array $datasetItem): mixed { return null; }'
            . ' public function evaluate(mixed $output, array $datasetItem): void {}';
    }
}
