<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Persistence;

use JsonException;
use NeuronAI\Exceptions\PersistenceException;
use NeuronAI\Exceptions\WorkflowException;

use function base64_decode;
use function base64_encode;
use function file_get_contents;
use function file_put_contents;
use function is_array;
use function is_dir;
use function is_file;
use function is_string;
use function json_decode;
use function json_encode;
use function mkdir;
use function rawurlencode;
use function rename;
use function strlen;
use function tempnam;
use function unlink;

use const DIRECTORY_SEPARATOR;
use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;

/**
 * One file per partition in the configured directory (`<partition>.store`),
 * containing a versioned JSON envelope of base64-encoded key/value strings.
 *
 * This backend provides restart durability for controlled single-process use.
 * File replacement is deliberately not a lock or CAS protocol for concurrent
 * workers; use a transactional database backend there.
 */
class FilePersistence implements PersistenceInterface
{
    public function __construct(
        protected string $directory,
    ) {
        if (!is_dir($this->directory) && !mkdir($this->directory, 0o700, true)) {
            throw new WorkflowException("Unable to create directory '{$this->directory}'");
        }
    }

    public function get(string $partition, string $key): ?string
    {
        return $this->getData($partition)[$key] ?? null;
    }

    public function initializeIfAbsent(
        string $partition,
        string $conditionKey,
        string $initialValue,
        array $records = [],
    ): bool {
        $data = $this->getData($partition);
        if (isset($data[$conditionKey])) {
            return false;
        }

        $records[$conditionKey] = $initialValue;
        foreach ($records as $key => $value) {
            $data[$key] = $value;
        }

        $this->writePartition($partition, $data);

        return true;
    }

    public function writeIfUnchanged(
        string $partition,
        string $conditionKey,
        string $expectedValue,
        array $records,
    ): bool {
        $data = $this->getData($partition);
        if (($data[$conditionKey] ?? null) !== $expectedValue) {
            return false;
        }

        foreach ($records as $key => $value) {
            $data[$key] = $value;
        }

        $this->writePartition($partition, $data);

        return true;
    }

    public function deleteIfUnchanged(
        string $partition,
        string $conditionKey,
        string $expectedValue,
    ): bool {
        if (($this->getData($partition)[$conditionKey] ?? null) !== $expectedValue) {
            return false;
        }

        $path = $this->filePath($partition);

        if (is_file($path) && !@unlink($path)) {
            throw new WorkflowException("Unable to delete partition '{$partition}' at '{$path}'.");
        }

        return true;
    }

    /** @return array<string, string> */
    protected function getData(string $partition): array
    {
        return $this->readFile($this->filePath($partition));
    }

    /** @return array<string, string> */
    protected function readFile(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }

        $contents = @file_get_contents($path);
        if ($contents === false) {
            throw new PersistenceException("Unable to read Workflow partition at '{$path}'.");
        }

        try {
            $data = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new PersistenceException("Corrupted Workflow partition at '{$path}'.", $e->getCode(), previous: $e);
        }

        if (!is_array($data)) {
            throw new PersistenceException("Corrupted Workflow partition at '{$path}': expected a JSON object.");
        }

        if (($data['version'] ?? null) === 2 && is_array($data['records'] ?? null)) {
            return $this->decodeRecords($data['records'], $path);
        }

        foreach ($data as $key => $value) {
            if (!is_string($key) || !is_string($value)) {
                throw new PersistenceException(
                    "Corrupted Workflow partition at '{$path}': every key and value must be a string."
                );
            }
        }

        return $data;
    }

    /** @param array<string, string> $data */
    protected function writePartition(string $partition, array $data): void
    {
        $path = $this->filePath($partition);

        $records = [];
        foreach ($data as $key => $value) {
            $records[base64_encode($key)] = base64_encode($value);
        }

        try {
            $contents = json_encode([
                'version' => 2,
                'records' => $records,
            ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new PersistenceException(
                "Unable to encode Workflow partition '{$partition}'.",
                $e->getCode(),
                previous: $e,
            );
        }

        $temporaryPath = @tempnam($this->directory, '.workflow-');
        if ($temporaryPath === false) {
            throw new WorkflowException("Unable to write partition '{$partition}' to '{$path}'.");
        }

        try {
            $written = @file_put_contents($temporaryPath, $contents);
            if ($written !== strlen($contents)) {
                throw new WorkflowException("Unable to write partition '{$partition}' to '{$path}'.");
            }

            if (!@rename($temporaryPath, $path)) {
                throw new WorkflowException("Unable to write partition '{$partition}' to '{$path}'.");
            }
        } finally {
            if (is_file($temporaryPath)) {
                @unlink($temporaryPath);
            }
        }
    }

    /**
     * @param array<string, mixed> $records
     * @return array<string, string>
     */
    protected function decodeRecords(array $records, string $path): array
    {
        $decoded = [];
        foreach ($records as $key => $value) {
            $decodedKey = is_string($key) ? base64_decode($key, true) : false;
            $decodedValue = is_string($value) ? base64_decode($value, true) : false;

            if ($decodedKey === false || $decodedValue === false) {
                throw new PersistenceException("Corrupted Workflow partition at '{$path}': invalid encoded record.");
            }

            $decoded[$decodedKey] = $decodedValue;
        }

        return $decoded;
    }

    /**
     * Partition names are arbitrary business strings (a workflow ID may be
     * 'order:123' or contain slashes); encoding keeps every name a safe,
     * traversal-free filename while staying reversible and mostly readable.
     */
    protected function filePath(string $partition): string
    {
        return $this->directory . DIRECTORY_SEPARATOR . rawurlencode($partition) . '.store';
    }
}
