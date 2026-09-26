<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Evaluation\Config;

use Closure;
use NeuronAI\Evaluation\Config\ConfigLoader;
use NeuronAI\Evaluation\Output\ConsoleOutput;
use NeuronAI\Evaluation\Output\JsonOutput;
use NeuronAI\Evaluation\Runner\EvaluatorRunner;
use NeuronAI\Tests\Evaluation\Stub\GreetingEvaluator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function file_put_contents;
use function glob;
use function is_dir;
use function is_file;
use function rmdir;
use function sys_get_temp_dir;
use function unlink;
use function bin2hex;
use function chdir;
use function file_exists;
use function getcwd;
use function mkdir;
use function random_bytes;

class ConfigLoaderTest extends TestCase
{
    protected string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/neuron_test_' . bin2hex(random_bytes(8));
        if (!file_exists($this->tempDir)) {
            mkdir($this->tempDir, 0o777, true);
        }
    }

    protected function tearDown(): void
    {
        // Clean up temp directory
        $this->removeDirectory($this->tempDir);
    }

    protected function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = glob($dir . '/*');
        foreach ($files as $file) {
            if (is_dir($file)) {
                $this->removeDirectory($file);
            } elseif (is_file($file)) {
                unlink($file);
            }
        }
        rmdir($dir);
    }

    public function test_returns_default_config_when_no_config_file_exists(): void
    {
        $originalCwd = getcwd();
        chdir($this->tempDir);

        try {
            $loader = new ConfigLoader();
            $config = $loader->load();

            $this->assertArrayHasKey('output', $config);
            $this->assertIsArray($config['output']);
            $this->assertContains(ConsoleOutput::class, $config['output']);
        } finally {
            chdir($originalCwd);
        }
    }

    public function test_get_output_drivers_returns_default_when_no_config_file(): void
    {
        $originalCwd = getcwd();
        chdir($this->tempDir);

        try {
            $loader = new ConfigLoader();
            $drivers = $loader->getOutputDrivers();

            $this->assertContains(ConsoleOutput::class, $drivers);
        } finally {
            chdir($originalCwd);
        }
    }

    public function test_loads_config_from_evaluation_php(): void
    {
        $configFile = $this->tempDir . '/evaluation.php';
        file_put_contents($configFile, '<?php return ["output_drivers" => ["MyDriver"]];');

        $originalCwd = getcwd();
        chdir($this->tempDir);

        try {
            $loader = new ConfigLoader();
            $config = $loader->load();

            $this->assertEquals(['output_drivers' => ['MyDriver']], $config);
        } finally {
            chdir($originalCwd);
        }
    }

    public function test_prefers_root_config_over_config_directory(): void
    {
        $rootConfig = $this->tempDir . '/evaluation.php';
        file_put_contents($rootConfig, '<?php return ["output_drivers" => ["RootDriver"]];');

        mkdir($this->tempDir . '/config');
        $configDirConfig = $this->tempDir . '/config/evaluation.php';
        file_put_contents($configDirConfig, '<?php return ["output_drivers" => ["ConfigDirDriver"]];');

        $originalCwd = getcwd();
        chdir($this->tempDir);

        try {
            $loader = new ConfigLoader();
            $config = $loader->load();

            $this->assertEquals(['output_drivers' => ['RootDriver']], $config);
        } finally {
            chdir($originalCwd);
        }
    }

    public function test_reads_the_resolver_and_runner_entries(): void
    {
        file_put_contents($this->tempDir . '/evaluation.php', <<<'PHP'
            <?php

            return [
                'resolver' => fn (string $class): object => new $class('Hello'),
                'runner' => new \NeuronAI\Evaluation\Runner\EvaluatorRunner(),
            ];
            PHP);

        $originalCwd = getcwd();
        chdir($this->tempDir);

        try {
            $loader = new ConfigLoader();

            $this->assertInstanceOf(GreetingEvaluator::class, ($loader->getResolver())(GreetingEvaluator::class));
            $this->assertInstanceOf(EvaluatorRunner::class, $loader->getRunner());
        } finally {
            chdir($originalCwd);
        }
    }

    public function test_resolver_and_runner_are_absent_without_config(): void
    {
        $originalCwd = getcwd();
        chdir($this->tempDir);

        try {
            $loader = new ConfigLoader();

            $this->assertNull($loader->getResolver());
            $this->assertNull($loader->getRunner());
        } finally {
            chdir($originalCwd);
        }
    }

    /** @return array<string, array{string, string, string}> */
    public static function invalidEntryProvider(): array
    {
        return [
            'resolver' => ["'resolver' => 'not a function'", 'getResolver', "'resolver' entry of evaluation.php must be callable"],
            'runner' => ["'runner' => new stdClass()", 'getRunner', "'runner' entry of evaluation.php must be an EvaluatorRunner instance"],
        ];
    }

    #[DataProvider('invalidEntryProvider')]
    public function test_rejects_an_invalid_resolver_or_runner_entry(string $entry, string $getter, string $message): void
    {
        file_put_contents($this->tempDir . '/evaluation.php', "<?php return [{$entry}];");

        $originalCwd = getcwd();
        chdir($this->tempDir);

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage($message);

            (new ConfigLoader())->{$getter}();
        } finally {
            chdir($originalCwd);
        }
    }

    public function test_throws_exception_when_config_does_not_return_array(): void
    {
        $configFile = $this->tempDir . '/evaluation.php';
        file_put_contents($configFile, '<?php return "not an array";');

        $originalCwd = getcwd();
        chdir($this->tempDir);

        try {
            $loader = new ConfigLoader();
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Config file must return an array');

            $loader->load();
        } finally {
            chdir($originalCwd);
        }
    }

    public function test_get_output_drivers_reads_the_output_entry(): void
    {
        $this->withConfig("<?php return ['output' => [\\NeuronAI\\Evaluation\\Output\\JsonOutput::class, 'App\\\\CustomOutput']];", function (): void {
            $this->assertSame(
                [JsonOutput::class, 'App\\CustomOutput'],
                (new ConfigLoader())->getOutputDrivers()
            );
        });
    }

    public function test_get_output_drivers_defaults_to_console_when_the_entry_is_missing(): void
    {
        $this->withConfig("<?php return ['cache' => ['path' => 'x']];", function (): void {
            $this->assertSame([ConsoleOutput::class], (new ConfigLoader())->getOutputDrivers());
        });
    }

    public function test_cache_path_defaults_without_config(): void
    {
        $this->withConfig(null, function (): void {
            $this->assertSame('.neuron/cache/evaluation', (new ConfigLoader())->getCachePath());
        });
    }

    public function test_cache_path_reads_the_cache_entry(): void
    {
        $this->withConfig("<?php return ['cache' => ['path' => '/var/cache/neuron-evals']];", function (): void {
            $this->assertSame('/var/cache/neuron-evals', (new ConfigLoader())->getCachePath());
        });
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidCacheEntries(): iterable
    {
        yield 'non-string path' => ["['cache' => ['path' => 42]]"];
        yield 'null path' => ["['cache' => ['path' => null]]"];
        yield 'cache entry is a string' => ["['cache' => 'somewhere']"];
        yield 'cache entry without path' => ["['cache' => []]"];
    }

    #[DataProvider('invalidCacheEntries')]
    public function test_cache_path_falls_back_to_the_default_on_an_invalid_entry(string $config): void
    {
        $this->withConfig("<?php return {$config};", function (): void {
            $this->assertSame('.neuron/cache/evaluation', (new ConfigLoader())->getCachePath());
        });
    }

    public function test_resolver_accepts_any_callable(): void
    {
        $this->withConfig("<?php return ['resolver' => [\\NeuronAI\\Tests\\Evaluation\\Config\\ConfigLoaderTest::class, 'buildGreetingEvaluator']];", function (): void {
            $resolver = (new ConfigLoader())->getResolver();

            $this->assertInstanceOf(Closure::class, $resolver);
            $evaluator = $resolver(GreetingEvaluator::class);
            $this->assertInstanceOf(GreetingEvaluator::class, $evaluator);
            $this->assertSame('Hi, Ada', $evaluator->run(['name' => 'Ada']));
        });
    }

    public static function buildGreetingEvaluator(string $class): object
    {
        return new $class('Hi');
    }

    /**
     * Runs the callback with the working directory set to a directory holding
     * the given evaluation.php content (none when null).
     */
    protected function withConfig(?string $content, Closure $callback): void
    {
        if ($content !== null) {
            file_put_contents($this->tempDir . '/evaluation.php', $content);
        }

        $originalCwd = getcwd();
        chdir($this->tempDir);

        try {
            $callback();
        } finally {
            chdir($originalCwd);
        }
    }
}
