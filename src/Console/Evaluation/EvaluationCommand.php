<?php

declare(strict_types=1);

namespace NeuronAI\Console\Evaluation;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use NeuronAI\Console\Command;
use NeuronAI\Evaluation\Cache\FileEvaluationCache;
use NeuronAI\Evaluation\Config\ConfigLoader;
use NeuronAI\Evaluation\Config\EvaluationOutputResolver;
use NeuronAI\Evaluation\Contracts\EvaluatorInterface;
use NeuronAI\Evaluation\EvaluatorDiscovery;
use NeuronAI\Evaluation\Output\OutputPipeline;
use NeuronAI\Evaluation\Runner\EvaluationReport;
use NeuronAI\Evaluation\Runner\EvaluatorReport;
use NeuronAI\Evaluation\Runner\EvaluatorRunner;
use NeuronAI\Evaluation\Runner\EvaluationResults;
use ReflectionClass;
use RuntimeException;
use Throwable;

use function array_shift;
use function array_values;
use function count;
use function end;
use function explode;
use function str_starts_with;
use function substr;

class EvaluationCommand extends Command
{
    protected ?Closure $resolver;

    /**
     * @param EvaluatorRunner|null $runner Defaults to the 'runner' entry of evaluation.php.
     * @param callable|null $resolver Builds evaluators and output drivers given as class
     *        names, e.g. through the application's container: fn (string $class): object.
     *        Defaults to the 'resolver' entry of evaluation.php.
     */
    public function __construct(
        protected readonly ConfigLoader $configLoader = new ConfigLoader(),
        protected readonly EvaluatorDiscovery $discovery = new EvaluatorDiscovery(),
        protected readonly ?EvaluatorRunner $runner = null,
        ?callable $resolver = null,
    ) {
        $this->resolver = $resolver !== null ? $resolver(...) : null;
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
            return $this->executeEvaluations(
                $options['path'],
                $options['verbose'],
                $options['concurrency'],
                $options['cache'] || $options['fresh'],
                $options['fresh']
            );
        } catch (Throwable $e) {
            $this->printError($e->getMessage());
            return 1;
        }
    }

    /**
     * @param array<string> $args
     * @return array{path: string, verbose: bool, help: bool, concurrency: int, cache: bool, fresh: bool}
     */
    protected function parseArguments(array $args): array
    {
        $options = [
            'path' => '',
            'verbose' => false,
            'help' => false,
            'concurrency' => 1,
            'cache' => false,
            'fresh' => false,
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
            } elseif ($arg === '--cache') {
                $options['cache'] = true;
            } elseif ($arg === '--fresh') {
                $options['fresh'] = true;
            } elseif (empty($options['path']) && !str_starts_with($arg, '-')) {
                $options['path'] = $arg;
            }
        }

        return $options;
    }

    protected function executeEvaluations(string $path, bool $verbose, int $concurrency, bool $cache, bool $fresh): int
    {
        echo "Neuron AI Evaluation Runner\n\n";

        if ($concurrency > 1 && !EvaluatorRunner::supportsConcurrency()) {
            echo "Parallel execution requires the pcntl extension and spatie/fork. Running sequentially.\n\n";
            $concurrency = 1;
        }

        $resolver = $this->resolver ?? $this->configLoader->getResolver() ?? $this->instantiate(...);
        $runner = $this->runner ?? $this->configLoader->getRunner() ?? new EvaluatorRunner();

        if ($cache) {
            $runner = $runner->withCache(new FileEvaluationCache($this->configLoader->getCachePath()), $fresh);
        }

        $evaluationStartedAt = new DateTimeImmutable("now", new DateTimeZone("UTC"));
        $evaluatorClasses = $this->discovery->discover($path);

        if ($evaluatorClasses === []) {
            $this->printError("No evaluator classes found in: {$path}");
            return 1;
        }

        $reports = [];
        $total = count($evaluatorClasses);

        foreach (array_values($evaluatorClasses) as $index => $evaluatorClass) {
            if ($verbose) {
                echo "Running {$this->getShortClassName($evaluatorClass)}... [" . ($index + 1) . "/{$total}]\n";
            }

            $startedAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
            $namespace = null;

            try {
                $evaluator = $this->createEvaluator($evaluatorClass, $resolver);
                $namespace = $evaluator->namespace();
                $results = $runner->run($evaluator, $concurrency);
            } catch (Throwable $e) {
                $finishedAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
                $this->printError("Failed to run {$evaluatorClass}: " . $e->getMessage());
                $reports[] = new EvaluatorReport(
                    $evaluatorClass,
                    new EvaluationResults([]),
                    $startedAt,
                    $finishedAt,
                    $e->getMessage(),
                    $namespace,
                );
                continue;
            }

            $finishedAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));

            if (!$verbose) {
                $this->printProgress($results);
            }

            $reports[] = new EvaluatorReport(
                $evaluatorClass,
                $results,
                $startedAt,
                $finishedAt,
                namespace: $namespace,
            );
        }

        $report = new EvaluationReport(
            $reports,
            $evaluationStartedAt,
            new DateTimeImmutable("now", new DateTimeZone("UTC")),
        );
        $this->outputSummary($report, $resolver);

        return $report->hasFailures() ? 1 : 0;
    }

    protected function printProgress(EvaluationResults $results): void
    {
        foreach ($results->getResults() as $result) {
            echo $result->isPassed() ? '.' : 'F';
        }
    }

    /**
     * @param Closure(class-string): object $resolver
     */
    protected function outputSummary(EvaluationReport $report, Closure $resolver): void
    {
        // Build the pipeline only after all runs complete: drivers may hold live
        // resources (e.g. DB connections) that must not exist at fork time
        $drivers = (new EvaluationOutputResolver($resolver))->resolve($this->configLoader->getOutputDrivers());

        (new OutputPipeline($drivers))->output($report);
    }

    /**
     * @param class-string $className
     * @param Closure(class-string): object $resolver
     */
    protected function createEvaluator(string $className, Closure $resolver): EvaluatorInterface
    {
        return $resolver($className);
    }

    /**
     * The resolver when none is configured.
     *
     * @param class-string $className
     */
    protected function instantiate(string $className): object
    {
        $constructor = (new ReflectionClass($className))->getConstructor();

        if ($constructor !== null && $constructor->getNumberOfRequiredParameters() > 0) {
            throw new RuntimeException(
                "{$className} requires constructor arguments: build it with a resolver, "
                . "such as the 'resolver' entry of evaluation.php."
            );
        }

        return new $className();
    }

    protected function getShortClassName(string $fullClassName): string
    {
        $parts = explode('\\', $fullClassName);
        return end($parts);
    }

    protected function printUsage(): void
    {
        echo "Usage:\n";
        echo "  vendor/bin/neuron evaluation <path> [options]\n\n";
        echo "Arguments:\n";
        echo "  path                   Path to directory containing evaluators\n\n";
        echo "Options:\n";
        echo "  --concurrency=N        Run dataset items in N parallel processes (requires pcntl and spatie/fork)\n";
        echo "  --cache                Serve unchanged run() outputs from the evaluation cache (assertions always re-run)\n";
        echo "  --fresh                Re-run everything and overwrite the evaluation cache\n";
        echo "  --verbose, -v          Show verbose output\n";
        echo "  --help, -h             Show this help message\n";
    }
}
