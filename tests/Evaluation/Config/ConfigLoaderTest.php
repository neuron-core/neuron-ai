<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Evaluation\Config;

use NeuronAI\Evaluation\Config\ConfigLoader;
use NeuronAI\Evaluation\Output\ConsoleOutput;
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
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/neuron_test_' . bin2hex(random_bytes(8));
        if (!file_exists($this->tempDir)) {
            mkdir($this->tempDir, 0777, true);
        }
    }

    protected function tearDown(): void
    {
        // Clean up temp directory
        $this->removeDirectory($this->tempDir);
    }

    private function removeDirectory(string $dir): void
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

    public function testReturnsDefaultConfigWhenNoConfigFileExists(): void
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

    public function testGetOutputDriversReturnsDefaultWhenNoConfigFile(): void
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

    public function testLoadsConfigFromEvaluationPhp(): void
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

    public function testPrefersRootConfigOverConfigDirectory(): void
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

    public function testThrowsExceptionWhenConfigDoesNotReturnArray(): void
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
}
