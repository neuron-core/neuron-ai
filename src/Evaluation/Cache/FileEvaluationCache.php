<?php

declare(strict_types=1);

namespace NeuronAI\Evaluation\Cache;

use Throwable;

use function file_get_contents;
use function file_put_contents;
use function is_dir;
use function is_file;
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
        return is_file($this->path($key));
    }

    public function get(string $key): mixed
    {
        $data = @file_get_contents($this->path($key));

        if ($data === false) {
            return null;
        }

        return unserialize($data);
    }

    public function set(string $key, mixed $output): void
    {
        try {
            $payload = serialize($output);
        } catch (Throwable) {
            // Non-serializable output (same contract as the fork boundary):
            // the item is simply not cacheable.
            return;
        }

        if (!is_dir($this->directory) && !@mkdir($this->directory, 0755, true) && !is_dir($this->directory)) {
            return;
        }

        $target = $this->path($key);
        $tmp = $target . '.' . uniqid('', true) . '.tmp';

        if (@file_put_contents($tmp, $payload) !== false) {
            @rename($tmp, $target);
        }
    }

    protected function path(string $key): string
    {
        return $this->directory . DIRECTORY_SEPARATOR . $key . '.cache';
    }
}
