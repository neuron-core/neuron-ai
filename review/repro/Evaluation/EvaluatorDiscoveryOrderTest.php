<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Evaluation;

use Closure;
use FilesystemIterator;
use NeuronAI\Evaluation\EvaluatorDiscovery;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

use function array_map;
use function bin2hex;
use function file_put_contents;
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

class EvaluatorDiscoveryOrderTest extends TestCase
{
    protected string $directory;

    protected string $namespace;

    protected Closure $autoloader;

    protected function setUp(): void
    {
        $suffix = bin2hex(random_bytes(6));
        $this->directory = sys_get_temp_dir() . '/neuron-discovery-order-' . $suffix;
        $this->namespace = 'EvaluatorDiscoveryOrderFixture' . $suffix;
        mkdir($this->directory);

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

    public function test_discovery_order_is_sorted_by_path_regardless_of_creation_order(): void
    {
        $names = ['AlphaEvaluator', 'BravoEvaluator', 'CharlieEvaluator', 'DeltaEvaluator', 'EchoEvaluator', 'FoxtrotEvaluator', 'GolfEvaluator', 'HotelEvaluator'];

        foreach (['HotelEvaluator', 'CharlieEvaluator', 'FoxtrotEvaluator', 'AlphaEvaluator', 'GolfEvaluator', 'DeltaEvaluator', 'BravoEvaluator', 'EchoEvaluator'] as $name) {
            file_put_contents(
                "{$this->directory}/{$name}.php",
                "<?php\n\nnamespace {$this->namespace};\n\nclass {$name} extends \\NeuronAI\\Evaluation\\BaseEvaluator {"
                . ' public function getDataset(): \NeuronAI\Evaluation\Contracts\DatasetInterface'
                . ' { return new \NeuronAI\Evaluation\Dataset\ArrayDataset([]); }'
                . ' public function run(array $datasetItem): mixed { return null; }'
                . " public function evaluate(mixed \$output, array \$datasetItem): void {} }\n"
            );
        }

        $discovered = (new EvaluatorDiscovery())->discover($this->directory);

        $this->assertSame(array_map(fn (string $name): string => "{$this->namespace}\\{$name}", $names), $discovered);
    }
}
