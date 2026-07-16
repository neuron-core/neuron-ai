<?php

declare(strict_types=1);

namespace NeuronAI\Evaluation\Config;

use NeuronAI\Evaluation\Output\ConsoleOutput;
use RuntimeException;

use function is_array;
use function realpath;

class ConfigLoader
{
    protected const ROOT_CONFIG_FILE = 'evaluation.php';

    /**
     * @return array<string, mixed>
     */
    public function load(): array
    {
        // Prefer root config over config directory.
        // realpath() resolves the (optional, user-provided) config file to an
        // absolute path and returns false when it doesn't exist, which doubles
        // as the existence check.
        $file = realpath(self::ROOT_CONFIG_FILE);

        if ($file !== false) {
            $config = require $file;

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
    protected function getDefaultConfig(): array
    {
        return [
            'output' => [ConsoleOutput::class],
        ];
    }

    /**
     * @return array<string|int, mixed>
     */
    public function getOutputDrivers(): array
    {
        return $this->load()['output'] ?? [ConsoleOutput::class];
    }
}
