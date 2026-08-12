<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Persistence;

use NeuronAI\Exceptions\WorkflowException;

use function file_get_contents;
use function file_put_contents;
use function is_dir;
use function is_file;
use function json_decode;
use function json_encode;
use function rawurlencode;
use function unlink;
use function mkdir;

use const DIRECTORY_SEPARATOR;
use const JSON_PRETTY_PRINT;

/**
 * One file per partition in the configured directory (`<partition>.store`),
 * containing a JSON map of key => value strings.
 */
class FilePersistence implements PersistenceInterface
{
    /** @var array<string, array<string, string>> */
    protected array $cache = [];

    public function __construct(
        protected string $directory,
    ) {
        if (!is_dir($this->directory) && !mkdir($this->directory, 0o755, true)) {
            throw new WorkflowException("Unable to create directory '{$this->directory}'");
        }
    }

    public function put(string $partition, string $key, string $value): void
    {
        $data = $this->getData($partition);
        $data[$key] = $value;
        $this->cache[$partition] = $data;

        $path = $this->filePath($partition);

        // A dropped write would silently un-persist a durable record: an
        // over-long filename, a full disk, or missing permissions must
        // surface here, not at the next (impossible) resume.
        if (@file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT)) === false) {
            throw new WorkflowException("Unable to write partition '{$partition}' to '{$path}'.");
        }
    }

    public function get(string $partition, string $key): ?string
    {
        return $this->getData($partition)[$key] ?? null;
    }

    public function delete(string $partition): void
    {
        unset($this->cache[$partition]);

        $path = $this->filePath($partition);

        // A silently failed delete would leave the address reading as
        // "run in flight" forever — the sweep must fail as loudly as a write.
        if (is_file($path) && !@unlink($path)) {
            throw new WorkflowException("Unable to delete partition '{$partition}' at '{$path}'.");
        }
    }

    /** @return array<string, string> */
    protected function getData(string $partition): array
    {
        return $this->cache[$partition] ?? $this->cache[$partition] = $this->readFile($this->filePath($partition));
    }

    /** @return array<string, string> */
    protected function readFile(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }

        return json_decode(file_get_contents($path), true) ?? [];
    }

    /**
     * Partition names are arbitrary business strings (an address may be
     * 'order:123' or contain slashes); encoding keeps every name a safe,
     * traversal-free filename while staying reversible and mostly readable.
     */
    protected function filePath(string $partition): string
    {
        return $this->directory . DIRECTORY_SEPARATOR . rawurlencode($partition) . '.store';
    }
}
