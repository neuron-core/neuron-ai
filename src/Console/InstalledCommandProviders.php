<?php

declare(strict_types=1);

namespace NeuronAI\Console;

use RuntimeException;

use function file_get_contents;
use function is_file;
use function json_decode;

use const JSON_THROW_ON_ERROR;

/**
 * The command providers declared by the packages Composer installed.
 */
class InstalledCommandProviders
{
    /**
     * @param string $installedPackagesFile Composer's vendor/composer/installed.json.
     */
    public function __construct(protected string $installedPackagesFile)
    {
    }

    /**
     * @return CommandProvider[]
     */
    public function discover(): array
    {
        if (!is_file($this->installedPackagesFile)) {
            return [];
        }

        $installed = json_decode((string) file_get_contents($this->installedPackagesFile), true, flags: JSON_THROW_ON_ERROR);

        $providers = [];
        foreach ($installed['packages'] ?? [] as $package) {
            $providerClass = $package['extra']['neuron']['command-provider'] ?? null;

            if ($providerClass !== null) {
                $providers[] = $this->instantiate($package['name'], $providerClass);
            }
        }

        return $providers;
    }

    protected function instantiate(string $packageName, string $providerClass): CommandProvider
    {
        $provider = new $providerClass();

        if (!$provider instanceof CommandProvider) {
            throw new RuntimeException(
                "{$packageName} declares {$providerClass} as its command provider, "
                . 'but it does not implement ' . CommandProvider::class
            );
        }

        return $provider;
    }
}
