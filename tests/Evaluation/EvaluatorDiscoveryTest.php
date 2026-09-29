<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Evaluation;

use Closure;
use FilesystemIterator;
use InvalidArgumentException;
use NeuronAI\Evaluation\EvaluatorDiscovery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresPhp;
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

        $this->assertSame([
            "{$this->namespace}\\Nested\\Deeper\\RefundEvaluator",
            "{$this->namespace}\\SupportEvaluator",
        ], $discovered);
    }

    public function test_evaluators_are_returned_in_class_name_order(): void
    {
        $this->writeEvaluator('Zeta/AlphaEvaluator');
        $this->writeEvaluator('BetaEvaluator');
        $this->writeEvaluator('AlphaEvaluator');

        $discovered = (new EvaluatorDiscovery())->discover($this->directory);

        $this->assertSame([
            "{$this->namespace}\\AlphaEvaluator",
            "{$this->namespace}\\BetaEvaluator",
            "{$this->namespace}\\Zeta\\AlphaEvaluator",
        ], $discovered);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function declarationPrefixes(): iterable
    {
        yield 'final' => ['final '];
        yield 'attribute on the same line' => ['#[\\Attribute] final '];
        yield 'indented' => ['    '];
        yield 'doc comment on the same line' => ['/** Refunds. */ '];
    }

    #[DataProvider('declarationPrefixes')]
    public function test_discovers_evaluators_whatever_precedes_the_class_keyword(string $prefix): void
    {
        $this->writeClass('ModifiedEvaluator', $prefix . 'class ModifiedEvaluator extends \\NeuronAI\\Evaluation\\BaseEvaluator {' . $this->evaluatorMethods() . ' }');

        $discovered = (new EvaluatorDiscovery())->discover($this->directory);

        $this->assertSame(["{$this->namespace}\\ModifiedEvaluator"], $discovered);
    }

    #[RequiresPhp('>= 8.2')]
    public function test_discovers_readonly_evaluators(): void
    {
        // A readonly class cannot extend BaseEvaluator, so it implements the interface
        $this->writeClass(
            'ReadonlyEvaluator',
            'final readonly class ReadonlyEvaluator implements \\NeuronAI\\Evaluation\\Contracts\\EvaluatorInterface {'
            . ' public function namespace(): ?string { return null; }'
            . ' public function setUp(): void {}'
            . ' public function getDataset(): \\NeuronAI\\Evaluation\\Contracts\\DatasetInterface'
            . ' { return new \\NeuronAI\\Evaluation\\Dataset\\ArrayDataset([]); }'
            . ' public function run(array $datasetItem): mixed { return null; }'
            . ' public function performEvaluation(mixed $output, array $datasetItem): \\NeuronAI\\Evaluation\\AssertionOutcomes'
            . ' { return new \\NeuronAI\\Evaluation\\AssertionOutcomes(0, 0, [], []); } }'
        );

        $discovered = (new EvaluatorDiscovery())->discover($this->directory);

        $this->assertSame(["{$this->namespace}\\ReadonlyEvaluator"], $discovered);
    }

    public function test_class_constants_and_anonymous_classes_are_not_declarations(): void
    {
        $this->writeClass(
            'FactoryEvaluator',
            'class FactoryEvaluator extends \\NeuronAI\\Evaluation\\BaseEvaluator {' . $this->evaluatorMethods()
            . ' public function helpers(): array { return [self::class, new class {}]; } }'
        );

        $discovered = (new EvaluatorDiscovery())->discover($this->directory);

        $this->assertSame(["{$this->namespace}\\FactoryEvaluator"], $discovered);
    }

    public function test_each_class_takes_the_namespace_of_its_own_block(): void
    {
        mkdir("{$this->directory}/Shop");
        file_put_contents(
            "{$this->directory}/Shop/CartEvaluator.php",
            "<?php\n\nnamespace {$this->namespace}\\Shop {\n"
            . "class CartEvaluator extends \\NeuronAI\\Evaluation\\BaseEvaluator {{$this->evaluatorMethods()} }\n}\n\n"
            . "namespace {$this->namespace}\\Billing {\n"
            . "class InvoiceEvaluator extends \\NeuronAI\\Evaluation\\BaseEvaluator {{$this->evaluatorMethods()} }\n}\n"
        );

        $discovered = (new EvaluatorDiscovery())->discover($this->directory);

        $this->assertSame([
            "{$this->namespace}\\Billing\\InvoiceEvaluator",
            "{$this->namespace}\\Shop\\CartEvaluator",
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
