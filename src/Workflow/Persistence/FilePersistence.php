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

        file_put_contents($this->filePath($partition), json_encode($data, JSON_PRETTY_PRINT));
    }

    public function get(string $partition, string $key): ?string
    {
        return $this->getData($partition)[$key] ?? null;
    }

    public function delete(string $partition): void
    {
        unset($this->cache[$partition]);

        $path = $this->filePath($partition);

        if (is_file($path)) {
            unlink($path);
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

    protected function filePath(string $partition): string
    {
        return $this->directory . DIRECTORY_SEPARATOR . $partition . '.store';
    }
}
