<?php

declare(strict_types=1);

namespace NeuronAI\Evaluation\Runner;

use NeuronAI\Evaluation\AssertionOutcomes;
use NeuronAI\Evaluation\Cache\CacheKey;
use NeuronAI\Evaluation\Cache\EvaluationCacheInterface;
use NeuronAI\Evaluation\Contracts\EvaluatorInterface;
use Spatie\Fork\Fork;
use Throwable;

use function class_exists;
use function count;
use function function_exists;
use function get_debug_type;
use function microtime;
use function serialize;

class EvaluatorRunner
{
    /**
     * @param EvaluationCacheInterface|null $cache When set, run() outputs are
     *        cached by content fingerprint and unchanged items skip run();
     *        evaluate() always executes fresh against the cached output.
     * @param bool $refresh Bypass cache reads while still recording outputs
     *        (the --fresh flag).
     */
    public function __construct(
        protected readonly ?EvaluationCacheInterface $cache = null,
        protected readonly bool $refresh = false,
    ) {
    }

    /**
     * Run the evaluator and return a summary of results
     */
    public function run(EvaluatorInterface $evaluator, int $concurrency = 1): EvaluatorSummary
    {
        $evaluator->setUp();

        $dataset = $evaluator->getDataset();
        $data = $dataset->load();

        $startTime = microtime(true);

        $results = $concurrency > 1 && count($data) > 1 && self::supportsConcurrency()
            ? $this->runParallel($evaluator, $data, $concurrency)
            : $this->runSequential($evaluator, $data);

        return new EvaluatorSummary($results, microtime(true) - $startTime);
    }

    /**
     * Whether parallel execution is available on this system.
     * Requires the pcntl extension (not available on Windows) and spatie/fork.
     */
    public static function supportsConcurrency(): bool
    {
        return function_exists('pcntl_fork') && class_exists(Fork::class);
    }

    /**
     * @param array<int, array<string, mixed>> $data
     * @return array<EvaluatorResult>
     */
    protected function runSequential(EvaluatorInterface $evaluator, array $data): array
    {
        $results = [];

        foreach ($data as $index => $item) {
            $results[] = $this->runItem($evaluator, $index, $item);
        }

        return $results;
    }

    /**
     * Run dataset items in parallel across forked child processes.
     * Fork returns outputs keyed by task order, so results stay in dataset order.
     *
     * @param array<int, array<string, mixed>> $data
     * @return array<EvaluatorResult>
     */
    protected function runParallel(EvaluatorInterface $evaluator, array $data, int $concurrency): array
    {
        $tasks = [];

        foreach ($data as $index => $item) {
            $tasks[] = fn (): EvaluatorResult => $this->ensureSerializable(
                $this->runItem($evaluator, $index, $item)
            );
        }

        return Fork::new()
            ->concurrent($concurrency)
            ->run(...$tasks);
    }

    /**
     * @param array<string, mixed> $item
     */
    protected function runItem(EvaluatorInterface $evaluator, int $index, array $item): EvaluatorResult
    {
        $startTime = microtime(true);
        $error = null;
        $output = null;
        $outcomes = null;
        $cachedRun = false;

        try {
            $cacheKey = $this->cache instanceof EvaluationCacheInterface
                ? CacheKey::make($evaluator, $item)
                : null;

            if ($cacheKey !== null && !$this->refresh && $this->cache?->has($cacheKey) === true) {
                $output = $this->cache->get($cacheKey);
                $cachedRun = true;
            } else {
                $output = $evaluator->run($item);

                if ($cacheKey !== null) {
                    $this->cache?->set($cacheKey, $output);
                }
            }

            $outcomes = $evaluator->performEvaluation($output, $item);
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }

        $executionTime = microtime(true) - $startTime;

        return new EvaluatorResult(
            $evaluator::class,
            $index,
            $outcomes instanceof AssertionOutcomes && $outcomes->isPassed(),
            $item,
            $output,
            $executionTime,
            $outcomes instanceof AssertionOutcomes ? $outcomes->passedCount : 0,
            $outcomes instanceof AssertionOutcomes ? $outcomes->failedCount : 0,
            $outcomes instanceof AssertionOutcomes ? $outcomes->failures : [],
            $outcomes instanceof AssertionOutcomes ? $outcomes->scores : [],
            $error,
            $cachedRun
        );
    }

    /**
     * Results cross the fork boundary via serialize(). If the evaluator's output
     * is not serializable (e.g. holds a closure or a connection), replace it with
     * a placeholder instead of crashing the child process.
     */
    protected function ensureSerializable(EvaluatorResult $result): EvaluatorResult
    {
        try {
            serialize($result);
            return $result;
        } catch (Throwable) {
            return new EvaluatorResult(
                $result->getEvaluatorClass(),
                $result->getIndex(),
                $result->isPassed(),
                $result->getInput(),
                '[non-serializable output of type ' . get_debug_type($result->getOutput()) . ']',
                $result->getExecutionTime(),
                $result->getAssertionsPassed(),
                $result->getAssertionsFailed(),
                $result->getAssertionFailures(),
                $result->getScoreRecords(),
                $result->getError(),
                $result->isCachedRun()
            );
        }
    }
}
