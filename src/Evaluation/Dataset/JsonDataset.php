<?php

declare(strict_types=1);

namespace NeuronAI\Evaluation\Dataset;

use NeuronAI\Evaluation\Contracts\DatasetInterface;
use InvalidArgumentException;

class JsonDataset implements DatasetInterface
{
    public function __construct(
        private readonly string $filePath
    ) {
        if (!\file_exists($this->filePath)) {
            throw new InvalidArgumentException("Dataset file not found: {$this->filePath}");
        }
    }

    /**
     * @return array<array<string, mixed>>
     */
    public function load(): array
    {
        $content = \file_get_contents($this->filePath);

        if ($content === false) {
            throw new InvalidArgumentException("Cannot read dataset file: {$this->filePath}");
        }

        $data = \json_decode($content, true);

        if (\json_last_error() !== \JSON_ERROR_NONE) {
            throw new InvalidArgumentException("Invalid JSON in dataset file: " . \json_last_error_msg());
        }

        if (!\is_array($data)) {
            throw new InvalidArgumentException("Dataset must be an array of objects");
        }

        return $data;
    }
}
