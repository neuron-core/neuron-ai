<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Console;

use NeuronAI\Tests\Support\FileSystemSandbox;
use PHPUnit\Framework\TestCase;

use function dirname;
use function escapeshellarg;
use function exec;
use function file_put_contents;
use function var_export;

use const PHP_BINARY;

class NeuronBinTest extends TestCase
{
    use FileSystemSandbox;

    protected string $sandbox;

    protected function setUp(): void
    {
        $this->sandbox = $this->createSandbox('neuron-bin');
    }

    protected function tearDown(): void
    {
        $this->removeSandbox($this->sandbox);
    }

    public function test_loads_the_autoloader_named_by_the_composer_bin_proxy(): void
    {
        $root = dirname(__DIR__, 2);
        $projectAutoload = $this->sandbox . '/autoload.php';
        $proxy = $this->sandbox . '/neuron';

        file_put_contents(
            $projectAutoload,
            "<?php echo 'project autoloader', PHP_EOL; require " . var_export($root . '/vendor/autoload.php', true) . ';'
        );
        file_put_contents(
            $proxy,
            "<?php \$GLOBALS['_composer_autoload_path'] = " . var_export($projectAutoload, true) . ';'
            . ' return include ' . var_export($root . '/bin/neuron', true) . ';'
        );

        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($proxy) . ' --help 2>&1', $output, $exitCode);

        $this->assertSame(0, $exitCode);
        $this->assertSame('project autoloader', $output[0]);
    }
}
