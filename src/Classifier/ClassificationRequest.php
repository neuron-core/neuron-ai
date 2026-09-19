<?php

declare(strict_types=1);

namespace NeuronAI\Classifier;

use InvalidArgumentException;
use JsonException;

use function array_walk_recursive;
use function is_array;
use function is_scalar;
use function is_string;
use function json_encode;
use function trim;

use const JSON_THROW_ON_ERROR;

class ClassificationRequest
{
    /**
     * @param string|array<array-key, mixed> $input Text or JSON-compatible data containing only arrays, scalars and null.
     * @param array<string, Choice|Score|Boolean> $questions Questions keyed by non-empty string identifiers.
     */
    public function __construct(
        public readonly string|array $input,
        public readonly array $questions,
    ) {
        if ($questions === []) {
            throw new InvalidArgumentException('A classification request requires at least one question.');
        }

        foreach ($questions as $id => $question) {
            if (!is_string($id) || trim($id) === '') {
                throw new InvalidArgumentException('Question identifiers must be non-empty strings.');
            }

            if (!$question instanceof Choice && !$question instanceof Score && !$question instanceof Boolean) {
                throw new InvalidArgumentException('Questions must be Choice, Score or Boolean definitions.');
            }
        }

        try {
            json_encode($input, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('Classification input must be JSON-compatible.', 0, $exception);
        }

        if (is_array($input)) {
            array_walk_recursive($input, static function (mixed $value): void {
                if ($value !== null && !is_scalar($value)) {
                    throw new InvalidArgumentException('Classification input accepts only arrays, scalars and null.');
                }
            });
        }
    }
}
