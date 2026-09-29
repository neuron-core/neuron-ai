<?php

declare(strict_types=1);

namespace NeuronAI\Evaluation\Cache;

use Throwable;

use function array_key_exists;
use function file_get_contents;
use function file_put_contents;
use function hash;
use function is_array;
use function is_dir;
use function mkdir;
use function rename;
use function serialize;
use function uniqid;
use function unserialize;

use const DIRECTORY_SEPARATOR;

/**
 * One file per key. Writes go through a temp file + rename so concurrent
 * forked children (--concurrency) never observe a half-written entry.
 */
class FileEvaluationCache implements EvaluationCacheInterface
{
    public function __construct(
        protected readonly string $directory
    ) {
    }

    public function has(string $key): bool
    {
        return $this->read($key) !== null;
    }

    public function get(string $key): mixed
    {
        return $this->read($key)['output'] ?? null;
    }

    public function set(string $key, mixed $output): void
    {
        try {
            $payload = serialize(['output' => $output]);
        } catch (Throwable) {
            // Non-serializable output (same contract as the fork boundary):
            // the item is simply not cacheable.
            return;
        }

        if (!is_dir($this->directory) && !@mkdir($this->directory, 0o755, true) && !is_dir($this->directory)) {
            return;
        }

        $target = $this->path($key);
        $tmp = $target . '.' . uniqid('', true) . '.tmp';

        if (@file_put_contents($tmp, $payload) !== false) {
            @rename($tmp, $target);
        }
    }

    /**
     * The envelope tells a stored value from a truncated or foreign file, which
     * is a miss: the runner re-runs the item and set() overwrites the entry.
     *
     * @return array{output: mixed}|null
     */
    protected function read(string $key): ?array
    {
        $data = @file_get_contents($this->path($key));

        if ($data === false) {
            return null;
        }

        try {
            $entry = @unserialize($data);
        } catch (Throwable) {
            return null;
        }

        return is_array($entry) && array_key_exists('output', $entry) ? $entry : null;
    }

    /**
     * Hashing makes every key a safe file name, whatever it contains.
     */
    protected function path(string $key): string
    {
        return $this->directory . DIRECTORY_SEPARATOR . hash('sha256', $key) . '.cache';
    }
}
