<?php

declare(strict_types=1);

namespace NeuronAI\Evaluation\Config;

use NeuronAI\Evaluation\Output\ConsoleOutput;
use RuntimeException;

use function file_exists;
use function is_array;

class ConfigLoader
{
    protected const ROOT_CONFIG_FILE = 'evaluation.php';

    /**
     * @return array<string, mixed>
     */
    public function load(): array
    {
        // Prefer root config over config directory
        if (file_exists(self::ROOT_CONFIG_FILE)) {
            /** @phpstan-ignore require.fileNotFound */
            $config = require self::ROOT_CONFIG_FILE;

            if (!is_array($config)) {
                throw new RuntimeException('Config file must return an array');
            }

            return $config;
        }

        return $this->getDefaultConfig();
    }

    /**
     * @return array<string, mixed>
     */
    private function getDefaultConfig(): array
    {
        return [
            'output_drivers' => [ConsoleOutput::class],
        ];
    }

    /**
     * @return array<string|int, mixed>
     */
    public function getOutputDrivers(): array
    {
        return $this->load()['output_drivers'] ?? [ConsoleOutput::class];
    }
}
