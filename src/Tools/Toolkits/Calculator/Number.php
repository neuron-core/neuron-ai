<?php

declare(strict_types=1);

namespace NeuronAI\Tools\Toolkits\Calculator;

use function abs;
use function floor;
use function is_float;
use function is_int;
use function sprintf;

/**
 * Renders a numeric result for the model: integers verbatim, floats with the
 * 15 significant digits a double reliably carries, so the output never depends
 * on the `precision` ini setting and never shows binary rounding noise.
 */
class Number
{
    public static function format(int|float $value): string
    {
        if (is_float($value) && floor($value) === $value && abs($value) < 2 ** 53) {
            $value = (int) $value;
        }

        return is_int($value) ? (string) $value : sprintf('%.15g', $value);
    }
}
