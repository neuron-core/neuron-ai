<?php

declare(strict_types=1);

namespace NeuronAI\Evaluation\Runner;

use Closure;
use NeuronAI\Evaluation\AssertionOutcomes;
use NeuronAI\Evaluation\Cache\CacheKey;
use NeuronAI\Evaluation\Cache\EvaluationCacheInterface;
use NeuronAI\Evaluation\Contracts\EvaluatorInterface;
use NeuronAI\Evaluation\EvaluationException;
use Spatie\Fork\Fork;
use Throwable;

use function class_exists;
use function count;
use function function_exists;
use function get_debug_type;
use function is_array;
use function is_int;
use function ksort;
use function microtime;
use function serialize;
use function var_export;

class EvaluatorRunner
{
    protected ?Closure $beforeChild;

    protected ?Closure $afterChild;

    /**
     * @param EvaluationCacheInterface|null $cache When set, run() outputs are
     *        cached by content fingerprint and unchanged items skip run();
     *        evaluate() always executes fresh against the cached output.
     * @param bool $refresh Bypass cache reads while still recording outputs
     *        (the --fresh flag).
     * @param callable|null $beforeChild Runs in each child process of a concurrent
     *        run before its dataset item, to replace process-bound resources
     *        inherited from the parent, such as database connections.
     * @param callable|null $afterChild Runs in each child process after its dataset item.
     */
    public function __construct(
        protected ?EvaluationCacheInterface $cache = null,
        protected bool $refresh = false,
        ?callable $beforeChild = null,
        ?callable $afterChild = null,
    ) {
        $this->beforeChild = $beforeChild !== null ? $beforeChild(...) : null;
        $this->afterChild = $afterChild !== null ? $afterChild(...) : null;
    }

    /**
     * A copy of this runner that caches run() outputs in the given store.
     */
    public function withCache(EvaluationCacheInterface $cache, bool $refresh = false): static
    {
        $runner = clone $this;
        $runner->cache = $cache;
        $runner->refresh = $refresh;

        return $runner;
    }

    /**
     * Run the evaluator and return its results
     */
    public function run(EvaluatorInterface $evaluator, int $concurrency = 1): EvaluationResults
    {
        $evaluator->setUp();

        $data = $this->validateDataset($evaluator, $evaluator->getDataset()->load());

        $results = $concurrency > 1 && count($data) > 1 && self::supportsConcurrency()
            ? $this->runParallel($evaluator, $data, $concurrency)
            : $this->runSequential($evaluator, $data);

        return new EvaluationResults($results);
    }

    /**
     * Every dataset implementation reaches the runner here, so the shape of
     * the items is checked once, with a message naming the evaluator.
     *
     * @param array<mixed> $data
     * @return array<int, array<string, mixed>>
     * @throws EvaluationException
     */
    protected function validateDataset(EvaluatorInterface $evaluator, array $data): array
    {
        foreach ($data as $key => $item) {
            if (!is_int($key)) {
                throw new EvaluationException(
                    'The dataset of ' . $evaluator::class . ' must be a list of items: found the key ' . var_export($key, true) . '.'
                );
            }

            if (!is_array($item)) {
                throw new EvaluationException(
                    'The dataset of ' . $evaluator::class . " must be a list of items, each a JSON object or array: item {$key} is " . get_debug_type($item) . '.'
                );
            }
        }

        return $data;
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
     * Fork keys outputs by task order but inserts them as children finish,
     * so sorting by key restores the dataset order.
     *
     * @param array<int, array<string, mixed>> $data
     * @return array<EvaluatorResult>
     */
    protected function runParallel(EvaluatorInterface $evaluator, array $data, int $concurrency): array
    {
        $tasks = [];

        foreach ($data as $index => $item) {
            $tasks[] = fn (): EvaluatorResult => $this->ensureSerializable(
                $this->runItem($evaluator, $index, $item, forked: true)
            );
        }

        $results = Fork::new()
            ->concurrent($concurrency)
            ->run(...$tasks);

        ksort($results);

        return $results;
    }

    /**
     * A failing child hook fails the item like any other error: a child that
     * throws is killed before it writes its result.
     *
     * @param array<string, mixed> $item
     */
    protected function runItem(EvaluatorInterface $evaluator, int $index, array $item, bool $forked = false): EvaluatorResult
    {
        $startTime = microtime(true);
        $error = null;
        $output = null;
        $outcomes = null;
        $cachedRun = false;

        try {
            if ($forked && $this->beforeChild instanceof Closure) {
                ($this->beforeChild)();
            }

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
            } finally {
                if ($forked && $this->afterChild instanceof Closure) {
                    ($this->afterChild)();
                }
            }
        } catch (Throwable $e) {
            $error = $this->describeError($e);
        }

        $executionTime = microtime(true) - $startTime;

        return new EvaluatorResult(
            $evaluator::class,
            $index,
            $error === null && $outcomes instanceof AssertionOutcomes && $outcomes->isPassed(),
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
     * The class and the location tell an evaluator bug from a provider or
     * framework failure, which the message alone often cannot.
     */
    protected function describeError(Throwable $e): string
    {
        $message = $e->getMessage() !== '' ? ": {$e->getMessage()}" : '';

        return $e::class . "{$message} ({$e->getFile()}:{$e->getLine()})";
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
