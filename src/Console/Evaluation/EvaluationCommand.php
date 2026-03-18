<?php

declare(strict_types=1);

namespace NeuronAI\Console\Evaluation;

use NeuronAI\Evaluation\BaseEvaluator;
use NeuronAI\Evaluation\Config\ConfigLoader;
use NeuronAI\Evaluation\Config\OutputDriverResolver;
use NeuronAI\Evaluation\Discovery\EvaluatorDiscovery;
use NeuronAI\Evaluation\OutputDrivers\OutputPipeline;
use NeuronAI\Evaluation\Runner\EvaluatorSummary;
use NeuronAI\Evaluation\Runner\EvaluatorRunner;
use ReflectionClass;
use ReflectionException;
use Throwable;
use RuntimeException;

use function array_merge;
use function array_shift;
use function count;
use function end;
use function explode;
use function str_starts_with;
use function substr;

class EvaluationCommand
{
    private readonly EvaluatorDiscovery $discovery;
    private readonly EvaluatorRunner $runner;

    public function __construct(
        private readonly ?ConfigLoader $configLoader = new ConfigLoader(),
        private readonly ?OutputDriverResolver $driverResolver = new OutputDriverResolver()
    ) {
        $this->discovery = new EvaluatorDiscovery();
        $this->runner = new EvaluatorRunner();
    }

    /**
     * @param array<string> $args
     */
    public function run(array $args): int
    {
        $options = $this->parseArguments($args);

        if ($options['help']) {
            $this->printUsage();
            return 0;
        }

        if (empty($options['path'])) {
            $this->printError("Path argument is required");
            $this->printUsage();
            return 1;
        }

        try {
            return $this->executeEvaluations($options['path'], $options['verbose']);
        } catch (Throwable $e) {
            $this->printError($e->getMessage());
            return 1;
        }
    }

    /**
     * @param array<string> $args
     * @return array{path: string, verbose: bool, help: bool}
     */
    private function parseArguments(array $args): array
    {
        $options = [
            'path' => '',
            'verbose' => false,
            'help' => false,
        ];

        // Skip script name
        array_shift($args);

        foreach ($args as $arg) {
            if ($arg === '--help' || $arg === '-h') {
                $options['help'] = true;
            } elseif ($arg === '--verbose' || $arg === '-v') {
                $options['verbose'] = true;
            } elseif (str_starts_with($arg, '--path=')) {
                $options['path'] = substr($arg, 7); // Remove '--path='
            } elseif (!str_starts_with($arg, '-') && empty($options['path'])) {
                $options['path'] = $arg;
            }
        }

        return $options;
    }

    private function executeEvaluations(string $path, bool $verbose): int
    {
        // Load output drivers from config and create pipeline
        $driverConfigs = $this->configLoader->getOutputDrivers();
        $drivers = $this->driverResolver->resolve($driverConfigs);
        $pipeline = new OutputPipeline($drivers);

        // Print header
        echo "Neuron AI Evaluation Runner\n\n";

        // Discover evaluators
        $evaluatorClasses = $this->discovery->discover($path);

        if ($evaluatorClasses === []) {
            $this->printError("No evaluator classes found in: {$path}");
            return 1;
        }

        $totalFailures = 0;
        $evaluatorCount = 1;
        $totalEvaluators = count($evaluatorClasses);

        foreach ($evaluatorClasses as $evaluatorClass) {
            if ($verbose) {
                echo "Running {$this->getShortClassName($evaluatorClass)}... [{$evaluatorCount}/{$totalEvaluators}]\n";
            }

            try {
                $evaluator = $this->createEvaluator($evaluatorClass);

                $summary = $this->runner->run($evaluator);

                // Print progress symbols
                if (!$verbose) {
                    foreach ($summary->getResults() as $result) {
                        echo $result->isPassed() ? '.' : 'F';
                    }
                }

                if ($summary->hasFailures()) {
                    $totalFailures += $summary->getFailedCount();
                }

            } catch (Throwable $e) {
                $this->printError("Failed to run {$evaluatorClass}: " . $e->getMessage());
                $totalFailures++;
            }

            $evaluatorCount++;
        }

        // Final output through the pipeline (includes all configured drivers)
        $pipeline->output($this->createOverallSummary($evaluatorClasses));

        return $totalFailures > 0 ? 1 : 0;
    }

    private function createEvaluator(string $className): BaseEvaluator
    {
        try {
            $reflection = new ReflectionClass($className);
            $constructor = $reflection->getConstructor();

            if ($constructor === null || $constructor->getNumberOfRequiredParameters() === 0) {
                return $reflection->newInstance();
            }

            throw new RuntimeException(
                "Evaluator {$className} requires constructor parameters. " .
                "Please ensure evaluators can be instantiated without arguments."
            );

        } catch (ReflectionException $e) {
            throw new RuntimeException("Cannot instantiate evaluator {$className}: " . $e->getMessage(), $e->getCode(), $e);
        }
    }

    private function getShortClassName(string $fullClassName): string
    {
        $parts = explode('\\', $fullClassName);
        return end($parts);
    }

    private function createOverallSummary(array $evaluatorClasses): EvaluatorSummary
    {
        // This is a simplified overall summary - in a real implementation,
        // you'd want to collect all individual results
        $results = [];
        $totalTime = 0.0;

        foreach ($evaluatorClasses as $evaluatorClass) {
            try {
                $evaluator = $this->createEvaluator($evaluatorClass);
                $summary = $this->runner->run($evaluator);

                $results = array_merge($results, $summary->getResults());
                $totalTime += $summary->getTotalExecutionTime();
            } catch (Throwable) {
                // Skip failed evaluators for overall summary
            }
        }

        return new EvaluatorSummary($results, $totalTime);
    }

    private function printError(string $message): void
    {
        echo "Error: {$message}\n";
    }

    private function printUsage(): void
    {
        echo "Usage:\n";
        echo "  vendor/bin/evaluation <path> [options]\n\n";
        echo "Arguments:\n";
        echo "  path                   Path to directory containing evaluators\n\n";
        echo "Options:\n";
        echo "  --verbose, -v          Show verbose output\n";
        echo "  --help, -h             Show this help message\n";
    }
}
