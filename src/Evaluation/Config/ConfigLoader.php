<?php

declare(strict_types=1);

namespace NeuronAI\Evaluation\Config;

use Closure;
use NeuronAI\Evaluation\Output\ConsoleOutput;
use NeuronAI\Evaluation\Runner\EvaluatorRunner;
use RuntimeException;

use function is_array;
use function is_callable;
use function is_string;
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

    /**
     * The 'resolver' entry: builds evaluators and output drivers given as
     * class names, e.g. through the application's container.
     *
     * @return (Closure(class-string): object)|null
     */
    public function getResolver(): ?Closure
    {
        $resolver = $this->load()['resolver'] ?? null;

        if ($resolver !== null && !is_callable($resolver)) {
            throw new RuntimeException("The 'resolver' entry of evaluation.php must be callable");
        }

        return $resolver !== null ? $resolver(...) : null;
    }

    /**
     * The 'runner' entry, e.g. a runner configured with child process hooks.
     */
    public function getRunner(): ?EvaluatorRunner
    {
        $runner = $this->load()['runner'] ?? null;

        if ($runner !== null && !$runner instanceof EvaluatorRunner) {
            throw new RuntimeException("The 'runner' entry of evaluation.php must be an EvaluatorRunner instance");
        }

        return $runner;
    }

    /**
     * Directory for the run() output cache (--cache). Overridable via
     * a 'cache' => ['path' => ...] entry in evaluation.php.
     */
    public function getCachePath(): string
    {
        $path = $this->load()['cache']['path'] ?? '.neuron/cache/evaluation';

        return is_string($path) ? $path : '.neuron/cache/evaluation';
    }
}
