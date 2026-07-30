<?php

declare(strict_types=1);

namespace NeuronAI\Console\Evaluation;

use NeuronAI\Evaluation\BaseEvaluator;
use NeuronAI\Evaluation\Config\ConfigLoader;
use NeuronAI\Evaluation\Config\EvaluationOutputResolver;
use NeuronAI\Evaluation\Discovery\EvaluatorDiscovery;
use NeuronAI\Evaluation\Output\OutputPipeline;
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
        private readonly ?EvaluationOutputResolver $driverResolver = new EvaluationOutputResolver()
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

        if ($options['concurrency'] < 1) {
            $this->printError("Concurrency must be a positive integer");
            return 1;
        }

        try {
            return $this->executeEvaluations($options['path'], $options['verbose'], $options['concurrency']);
        } catch (Throwable $e) {
            $this->printError($e->getMessage());
            return 1;
        }
    }

    /**
     * @param array<string> $args
     * @return array{path: string, verbose: bool, help: bool, concurrency: int}
     */
    private function parseArguments(array $args): array
    {
        $options = [
            'path' => '',
            'verbose' => false,
            'help' => false,
            'concurrency' => 1,
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
            } elseif (str_starts_with($arg, '--concurrency=')) {
                $options['concurrency'] = (int) substr($arg, 14); // Remove '--concurrency='
            } elseif (empty($options['path']) && !str_starts_with($arg, '-')) {
                $options['path'] = $arg;
            }
        }

        return $options;
    }

    private function executeEvaluations(string $path, bool $verbose, int $concurrency): int
    {
        // Print header
        echo "Neuron AI Evaluation Runner\n\n";

        if ($concurrency > 1 && !EvaluatorRunner::supportsConcurrency()) {
            echo "Parallel execution requires the pcntl extension and spatie/fork. Running sequentially.\n\n";
            $concurrency = 1;
        }

        // Discover evaluators
        $evaluatorClasses = $this->discovery->discover($path);

        if ($evaluatorClasses === []) {
            $this->printError("No evaluator classes found in: {$path}");
            return 1;
        }

        $totalFailures = 0;
        $evaluatorCount = 1;
        $totalEvaluators = count($evaluatorClasses);
        $allResults = [];
        $totalTime = 0.0;

        foreach ($evaluatorClasses as $evaluatorClass) {
            if ($verbose) {
                echo "Running {$this->getShortClassName($evaluatorClass)}... [{$evaluatorCount}/{$totalEvaluators}]\n";
            }

            try {
                $evaluator = $this->createEvaluator($evaluatorClass);

                $summary = $this->runner->run($evaluator, $concurrency);

                // Print progress symbols
                if (!$verbose) {
                    foreach ($summary->getResults() as $result) {
                        echo $result->isPassed() ? '.' : 'F';
                    }
                }

                if ($summary->hasFailures()) {
                    $totalFailures += $summary->getFailedCount();
                }

                $allResults = array_merge($allResults, $summary->getResults());
                $totalTime += $summary->getTotalExecutionTime();

            } catch (Throwable $e) {
                $this->printError("Failed to run {$evaluatorClass}: " . $e->getMessage());
                $totalFailures++;
            }

            $evaluatorCount++;
        }

        // Build the pipeline only after all runs complete: drivers may hold live
        // resources (e.g. DB connections) that must not exist at fork time
        $driverConfigs = $this->configLoader->getOutputDrivers();
        $drivers = $this->driverResolver->resolve($driverConfigs);
        $pipeline = new OutputPipeline($drivers);

        // Final output through the pipeline (includes all configured drivers)
        $pipeline->output(new EvaluatorSummary($allResults, $totalTime));

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
        echo "  --concurrency=N        Run dataset items in N parallel processes (requires pcntl and spatie/fork)\n";
        echo "  --verbose, -v          Show verbose output\n";
        echo "  --help, -h             Show this help message\n";
    }
}
