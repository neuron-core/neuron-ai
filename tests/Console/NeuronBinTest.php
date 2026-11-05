<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Console;

use NeuronAI\Tests\Console\Stub\GreetingCommandProvider;
use NeuronAI\Tests\Support\FileSystemSandbox;
use PHPUnit\Framework\TestCase;

use function dirname;
use function escapeshellarg;
use function exec;
use function file_put_contents;
use function json_encode;
use function mkdir;
use function var_export;

use const JSON_THROW_ON_ERROR;
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

    public function test_lists_the_commands_of_installed_packages_next_to_the_autoloader(): void
    {
        $root = dirname(__DIR__, 2);
        $proxy = $this->sandbox . '/neuron';

        mkdir($this->sandbox . '/composer');
        file_put_contents($this->sandbox . '/composer/installed.json', json_encode(['packages' => [
            ['name' => 'acme/greeting', 'extra' => ['neuron' => ['command-provider' => GreetingCommandProvider::class]]],
        ]], JSON_THROW_ON_ERROR));
        file_put_contents($this->sandbox . '/autoload.php', '<?php require ' . var_export($root . '/vendor/autoload.php', true) . ';');
        file_put_contents(
            $proxy,
            "<?php \$GLOBALS['_composer_autoload_path'] = " . var_export($this->sandbox . '/autoload.php', true) . ';'
            . ' return include ' . var_export($root . '/bin/neuron', true) . ';'
        );

        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($proxy) . ' greeting:hello 2>&1', $output, $exitCode);

        $this->assertSame(0, $exitCode);
        $this->assertSame(['Hello from an installed package'], $output);
    }
}
