<?php

declare(strict_types=1);

namespace NeuronAI\Console\Evaluation;

use NeuronAI\Evaluation\BaseEvaluator;
use NeuronAI\Evaluation\Discovery\EvaluatorDiscovery;
use NeuronAI\Evaluation\Runner\EvaluatorSummary;
use NeuronAI\Evaluation\Runner\EvaluatorRunner;
use ReflectionClass;
use ReflectionException;
use Throwable;

class EvaluationCommand
{
    private OutputFormatter $formatter;
    private readonly EvaluatorDiscovery $discovery;
    private readonly EvaluatorRunner $runner;

    public function __construct()
    {
        $this->discovery = new EvaluatorDiscovery();
        $this->runner = new EvaluatorRunner();
    }

    /**
     * @param array<string> $args
     */
    public function run(array $args): int
    {
        $options = $this->parseArguments($args);
        $this->formatter = new OutputFormatter($options['verbose']);

        if ($options['help']) {
            $this->formatter->printUsage();
            return 0;
        }

        if (empty($options['path'])) {
            $this->formatter->printError("Path argument is required");
            $this->formatter->printUsage();
            return 1;
        }

        try {
            return $this->executeEvaluations($options['path']);
        } catch (Throwable $e) {
            $this->formatter->printError($e->getMessage());
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
        \array_shift($args);

        foreach ($args as $arg) {
            if ($arg === '--help' || $arg === '-h') {
                $options['help'] = true;
            } elseif ($arg === '--verbose' || $arg === '-v') {
                $options['verbose'] = true;
            } elseif (\str_starts_with($arg, '--path=')) {
                $options['path'] = \substr($arg, 7); // Remove '--path='
            } elseif (!\str_starts_with($arg, '-') && empty($options['path'])) {
                $options['path'] = $arg;
            }
        }

        return $options;
    }

    private function executeEvaluations(string $path): int
    {
        $this->formatter->printHeader();

        // Discover evaluators
        $evaluatorClasses = $this->discovery->discover($path);

        if ($evaluatorClasses === []) {
            $this->formatter->printError("No evaluator classes found in: {$path}");
            return 1;
        }

        $totalFailures = 0;
        $evaluatorCount = 1;
        $totalEvaluators = \count($evaluatorClasses);

        foreach ($evaluatorClasses as $evaluatorClass) {
            $this->formatter->printProgress(
                $this->getShortClassName($evaluatorClass),
                $evaluatorCount,
                $totalEvaluators
            );

            try {
                $evaluator = $this->createEvaluator($evaluatorClass);

                $summary = $this->runner->run($evaluator);

                // Print progress symbols
                foreach ($summary->getResults() as $result) {
                    $this->formatter->printProgressSymbol($result->isPassed());
                }

                if ($summary->hasFailures()) {
                    $totalFailures += $summary->getFailedCount();
                }

            } catch (Throwable $e) {
                $this->formatter->printError("Failed to run {$evaluatorClass}: " . $e->getMessage());
                $totalFailures++;
            }

            $evaluatorCount++;
        }

        $this->formatter->printSummary($this->createOverallSummary($evaluatorClasses));

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

            throw new \RuntimeException(
                "Evaluator {$className} requires constructor parameters. " .
                "Please ensure evaluators can be instantiated without arguments."
            );

        } catch (ReflectionException $e) {
            throw new \RuntimeException("Cannot instantiate evaluator {$className}: " . $e->getMessage(), $e->getCode(), $e);
        }
    }


    private function getShortClassName(string $fullClassName): string
    {
        $parts = \explode('\\', $fullClassName);
        return \end($parts);
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

                $results = \array_merge($results, $summary->getResults());
                $totalTime += $summary->getTotalExecutionTime();
            } catch (Throwable) {
                // Skip failed evaluators for overall summary
            }
        }

        return new EvaluatorSummary($results, $totalTime);
    }
}
