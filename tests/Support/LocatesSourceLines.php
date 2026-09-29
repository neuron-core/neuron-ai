<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Support;

use function file;
use function str_contains;

/**
 * Finds a line in a fixture's source, so tests about reported locations
 * don't break whenever the fixture is edited.
 */
trait LocatesSourceLines
{
    protected function lineContaining(string $file, string $needle): int
    {
        foreach ((array) file($file) as $number => $line) {
            if (str_contains((string) $line, $needle)) {
                return $number + 1;
            }
        }

        $this->fail("'{$needle}' not found in {$file}");
    }
}
