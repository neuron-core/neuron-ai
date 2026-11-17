<?php

declare(strict_types=1);

namespace NeuronAI\Evaluation\Dataset;

use NeuronAI\Evaluation\Contracts\NamedDatasetInterface;
use InvalidArgumentException;

use function basename;
use function file_exists;
use function file_get_contents;
use function getcwd;
use function is_array;
use function json_decode;
use function json_last_error;
use function json_last_error_msg;
use function realpath;
use function str_replace;
use function str_starts_with;
use function strlen;
use function substr;

use const DIRECTORY_SEPARATOR;
use const JSON_ERROR_NONE;

class JsonDataset implements NamedDatasetInterface
{
    public function __construct(
        private readonly string $filePath
    ) {
        if (!file_exists($this->filePath)) {
            throw new InvalidArgumentException("Dataset file not found: {$this->filePath}");
        }
    }

    /**
     * The path of the file from the working directory, the same on every machine
     * that runs the evaluation from the project root; the file name alone when
     * the file is elsewhere, since a full path tells where it sits on one machine.
     */
    public function getName(): string
    {
        $path = realpath($this->filePath);
        $directory = realpath((string) getcwd());

        if ($path === false || $directory === false || !str_starts_with($path, $directory . DIRECTORY_SEPARATOR)) {
            return basename($this->filePath);
        }

        return str_replace(DIRECTORY_SEPARATOR, '/', substr($path, strlen($directory) + 1));
    }

    /**
     * @return array<array<string, mixed>>
     */
    public function load(): array
    {
        $content = file_get_contents($this->filePath);

        if ($content === false) {
            throw new InvalidArgumentException("Cannot read dataset file: {$this->filePath}");
        }

        $data = json_decode($content, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new InvalidArgumentException("Invalid JSON in dataset file: " . json_last_error_msg());
        }

        if (!is_array($data)) {
            throw new InvalidArgumentException("Dataset must be a JSON array of items");
        }

        return $data;
    }
}
