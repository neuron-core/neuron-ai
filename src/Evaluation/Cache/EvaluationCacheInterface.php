<?php

declare(strict_types=1);

namespace NeuronAI\Evaluation\Cache;

/**
 * Stores the output of Evaluator::run() keyed by a content fingerprint,
 * so unchanged dataset items can skip the expensive agent run while
 * evaluate() still executes against the cached output.
 */
interface EvaluationCacheInterface
{
    public function has(string $key): bool;

    /**
     * Returns the cached run() output. Call has() first: a missing
     * key returns null, which is indistinguishable from a cached null.
     */
    public function get(string $key): mixed;

    /**
     * Stores a run() output. Implementations silently skip values that
     * cannot be stored (e.g. non-serializable outputs) — a cache miss is
     * always acceptable, a crash is not.
     */
    public function set(string $key, mixed $output): void;
}
