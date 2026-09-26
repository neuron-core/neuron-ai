<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Evaluation;

use NeuronAI\Evaluation\EvaluatorDiscovery;
use PHPUnit\Framework\TestCase;

use function bin2hex;
use function file_put_contents;
use function is_file;
use function mkdir;
use function random_bytes;
use function rmdir;
use function spl_autoload_register;
use function spl_autoload_unregister;
use function str_starts_with;
use function strlen;
use function substr;
use function sys_get_temp_dir;
use function unlink;

class EvaluatorDiscoveryModifiersTest extends TestCase
{
    public function test_discovers_final_evaluator_classes(): void
    {
        $namespace = 'DiscoveryFixture' . bin2hex(random_bytes(6));
        $directory = sys_get_temp_dir() . '/' . $namespace;
        mkdir($directory);
        $methods = ' public function getDataset(): \NeuronAI\Evaluation\Contracts\DatasetInterface'
            . ' { return new \NeuronAI\Evaluation\Dataset\ArrayDataset([]); }'
            . ' public function run(array $datasetItem): mixed { return null; }'
            . ' public function evaluate(mixed $output, array $datasetItem): void {}';
        file_put_contents("{$directory}/FinalEvaluator.php", "<?php\nnamespace {$namespace};\n"
            . "final class FinalEvaluator extends \\NeuronAI\\Evaluation\\BaseEvaluator {{$methods}}\n");
        // PSR-4 style autoloading of the fixture namespace
        $autoloader = static function (string $class) use ($namespace, $directory): void {
            $file = $directory . '/' . substr($class, strlen($namespace) + 1) . '.php';
            if (str_starts_with($class, $namespace . '\\') && is_file($file)) {
                require $file;
            }
        };
        spl_autoload_register($autoloader);

        try {
            $discovered = (new EvaluatorDiscovery())->discover($directory);
        } finally {
            spl_autoload_unregister($autoloader);
            unlink("{$directory}/FinalEvaluator.php");
            rmdir($directory);
        }

        $this->assertSame(["{$namespace}\\FinalEvaluator"], $discovered);
    }
}
