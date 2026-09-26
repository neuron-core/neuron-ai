<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Evaluation;

use Closure;
use NeuronAI\Evaluation\EvaluatorDiscovery;
use NeuronAI\Tests\Support\FileSystemSandbox;
use PHPUnit\Framework\TestCase;

use function array_pop;
use function bin2hex;
use function explode;
use function file_put_contents;
use function implode;
use function is_file;
use function mkdir;
use function random_bytes;
use function spl_autoload_register;
use function spl_autoload_unregister;
use function str_replace;
use function str_starts_with;
use function strlen;
use function substr;

use const PHP_OS_FAMILY;

class EvaluatorDiscoveryFilesystemSecurityTest extends TestCase
{
    use FileSystemSandbox;

    protected string $directory;

    /**
     * Unique per test: discovered classes are declared for the rest of the process.
     */
    protected string $namespace;

    protected Closure $autoloader;

    protected function setUp(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('Symlinks need elevated privileges on Windows.');
        }

        $this->directory = $this->createSandbox('neuron_discovery_links');
        $this->namespace = 'EvaluatorDiscoveryLinkFixture' . bin2hex(random_bytes(6));

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
        if (PHP_OS_FAMILY === 'Windows') {
            return;
        }

        spl_autoload_unregister($this->autoloader);
        $this->removeSandbox($this->directory);
    }

    public function test_a_symlink_loop_is_not_followed(): void
    {
        mkdir($this->directory . '/Nested');
        $this->writeEvaluator('Nested/LoopEvaluator');
        $this->symlinkOrSkip($this->directory, $this->directory . '/Nested/Back');

        $discovered = (new EvaluatorDiscovery())->discover($this->directory);

        $this->assertSame(["{$this->namespace}\\Nested\\LoopEvaluator"], $discovered);
    }

    protected function writeEvaluator(string $relativeClass): void
    {
        $parts = explode('/', $relativeClass);
        $class = array_pop($parts);
        $namespace = implode('\\', [$this->namespace, ...$parts]);

        file_put_contents(
            $this->directory . '/' . $relativeClass . '.php',
            "<?php\n\nnamespace {$namespace};\n\n"
            . "class {$class} extends \\NeuronAI\\Evaluation\\BaseEvaluator\n{\n"
            . "    public function getDataset(): \\NeuronAI\\Evaluation\\Contracts\\DatasetInterface { return new \\NeuronAI\\Evaluation\\Dataset\\ArrayDataset([]); }\n"
            . "    public function run(array \$datasetItem): mixed { return null; }\n"
            . "    public function evaluate(mixed \$output, array \$datasetItem): void {}\n"
            . "}\n"
        );
    }
}
