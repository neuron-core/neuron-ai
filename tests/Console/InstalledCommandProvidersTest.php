<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Console;

use NeuronAI\Console\CommandProvider;
use NeuronAI\Console\InstalledCommandProviders;
use NeuronAI\Tests\Console\Stub\GreetingCommandProvider;
use NeuronAI\Tests\Support\FileSystemSandbox;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;

use function file_put_contents;
use function json_encode;

use const JSON_THROW_ON_ERROR;

class InstalledCommandProvidersTest extends TestCase
{
    use FileSystemSandbox;

    protected string $sandbox;

    protected function setUp(): void
    {
        $this->sandbox = $this->createSandbox('installed-command-providers');
    }

    protected function tearDown(): void
    {
        $this->removeSandbox($this->sandbox);
    }

    public function test_discovers_the_providers_declared_by_installed_packages(): void
    {
        $file = $this->installedPackages([
            ['name' => 'acme/without-commands', 'extra' => ['branch-alias' => ['dev-main' => '1.x-dev']]],
            ['name' => 'acme/greeting', 'extra' => ['neuron' => ['command-provider' => GreetingCommandProvider::class]]],
            ['name' => 'acme/without-extra'],
        ]);

        $providers = (new InstalledCommandProviders($file))->discover();

        $this->assertCount(1, $providers);
        $this->assertInstanceOf(GreetingCommandProvider::class, $providers[0]);
    }

    public function test_a_missing_installed_packages_file_has_no_providers(): void
    {
        $providers = (new InstalledCommandProviders($this->sandbox . '/missing.json'))->discover();

        $this->assertSame([], $providers);
    }

    public function test_a_declared_class_that_is_not_a_command_provider_is_reported_with_its_package(): void
    {
        $file = $this->installedPackages([
            ['name' => 'acme/broken', 'extra' => ['neuron' => ['command-provider' => stdClass::class]]],
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'acme/broken declares stdClass as its command provider, but it does not implement ' . CommandProvider::class
        );

        (new InstalledCommandProviders($file))->discover();
    }

    /**
     * @param array<array<string, mixed>> $packages
     */
    protected function installedPackages(array $packages): string
    {
        $file = $this->sandbox . '/installed.json';
        file_put_contents($file, json_encode(['packages' => $packages], JSON_THROW_ON_ERROR));

        return $file;
    }
}
