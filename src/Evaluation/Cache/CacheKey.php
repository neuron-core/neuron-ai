<?php

declare(strict_types=1);

namespace NeuronAI\Evaluation\Cache;

use Composer\InstalledVersions;
use NeuronAI\Evaluation\BaseEvaluator;
use NeuronAI\Evaluation\Contracts\EvaluatorInterface;
use NeuronAI\Evaluation\EvaluationException;
use ReflectionClass;
use ReflectionMethod;
use Throwable;

use function array_slice;
use function class_exists;
use function file;
use function hash;
use function hash_file;
use function implode;
use function is_file;
use function serialize;

/**
 * Content fingerprint for a (evaluator, dataset item) pair — the "impact
 * analysis" of the evaluation cache. The key covers exactly what determines
 * the output of run():
 *
 *   - the evaluator class name and the source of its run() method
 *     (so editing evaluate()/assertions does NOT invalidate cached runs)
 *   - the declared cache dependencies (agent classes, prompt files)
 *   - the dataset item content (content-addressed: reordering a dataset
 *     doesn't invalidate, appended items simply miss)
 *   - the installed framework version
 */
class CacheKey
{
    /**
     * Returns null when the dataset item cannot be fingerprinted
     * (non-serializable content) — the item is then simply not cacheable.
     *
     * @param array<string, mixed> $item
     * @throws EvaluationException on an unresolvable declared dependency
     */
    public static function make(EvaluatorInterface $evaluator, array $item): ?string
    {
        try {
            $itemFingerprint = serialize($item);
        } catch (Throwable) {
            return null;
        }

        return hash('sha256', implode('|', [
            $evaluator::class,
            self::runMethodHash($evaluator),
            self::dependenciesHash($evaluator),
            $itemFingerprint,
            self::frameworkVersion(),
        ]));
    }

    /**
     * Hash of the run() method source lines. Surgical on purpose: editing
     * assertions in evaluate() must not throw away recorded runs, since
     * evaluate() always re-executes against the cached output.
     */
    public static function runMethodHash(EvaluatorInterface $evaluator): string
    {
        $method = new ReflectionMethod($evaluator, 'run');
        $file = $method->getFileName();
        $start = $method->getStartLine();
        $end = $method->getEndLine();

        if ($file === false || $start === false || $end === false) {
            throw new EvaluationException(
                'Cannot fingerprint the run() method of ' . $evaluator::class . ' for caching.'
            );
        }

        $lines = array_slice(file($file) ?: [], $start - 1, $end - $start + 1);

        return hash('sha256', implode('', $lines));
    }

    protected static function dependenciesHash(EvaluatorInterface $evaluator): string
    {
        if (!$evaluator instanceof BaseEvaluator) {
            return '';
        }

        $hashes = [];

        foreach ($evaluator->cacheDependencies() as $dependency) {
            $file = class_exists($dependency)
                ? (new ReflectionClass($dependency))->getFileName()
                : $dependency;

            $hash = $file !== false && is_file($file) ? hash_file('sha256', $file) : false;

            if ($hash === false) {
                throw new EvaluationException(
                    "Cache dependency '{$dependency}' of " . $evaluator::class .
                    ' does not resolve to a readable source file.'
                );
            }

            $hashes[] = $hash;
        }

        return implode('|', $hashes);
    }

    protected static function frameworkVersion(): string
    {
        try {
            return InstalledVersions::getVersion('neuron-core/neuron-ai') ?? 'dev';
        } catch (Throwable) {
            // Not installed as a dependency (e.g. running inside the framework repo)
            return 'dev';
        }
    }
}
