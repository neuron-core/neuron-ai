<?php

declare(strict_types=1);

namespace NeuronAI\Tools\Toolkits\Calculator;

use NeuronAI\Exceptions\ToolException;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolOutput;

use function bcmod;
use function bcmul;
use function bcpowmod;
use function count;
use function extension_loaded;
use function intdiv;
use function is_int;

/**
 * Base of the tools that return exact integers of any size. They are built on
 * the bcmath extension and refuse to be constructed without it, so a missing
 * extension surfaces when the agent boots rather than mid-conversation.
 */
abstract class IntegerTool extends Tool
{
    protected const MILLER_RABIN_WITNESSES = [2, 3, 5, 7, 11, 13, 17, 19, 23, 29, 31, 37];

    public function __construct()
    {
        if (!extension_loaded('bcmath')) {
            throw new ToolException(static::class . ' requires the bcmath PHP extension (ext-bcmath).');
        }
    }

    /**
     * The feedback for a list that is not made of at least two integers, null when it is.
     */
    protected function invalidIntegers(array $numbers): ?ToolOutput
    {
        if (count($numbers) < 2) {
            return ToolOutput::error('Provide at least two integers.');
        }

        foreach ($numbers as $index => $number) {
            if (!is_int($number)) {
                return ToolOutput::error("The value at index {$index} is not an integer.");
            }
        }

        return null;
    }

    protected function gcd(string $a, string $b): string
    {
        while ($b !== '0') {
            [$a, $b] = [$b, bcmod($a, $b)];
        }

        return $a;
    }

    /**
     * Miller-Rabin with the first twelve primes as witnesses: deterministic for
     * every number below 3.3e24, hence for the whole int range.
     */
    protected function isPrime(int $number): bool
    {
        if ($number < 2) {
            return false;
        }

        foreach (self::MILLER_RABIN_WITNESSES as $witness) {
            if ($number % $witness === 0) {
                return $number === $witness;
            }
        }

        // number - 1 = odd * 2^doublings
        $odd = $number - 1;
        $doublings = 0;

        while ($odd % 2 === 0) {
            $odd = intdiv($odd, 2);
            $doublings++;
        }

        $modulus = (string) $number;
        $minusOne = (string) ($number - 1);

        foreach (self::MILLER_RABIN_WITNESSES as $witness) {
            $x = bcpowmod((string) $witness, (string) $odd, $modulus);

            if ($x === '1' || $x === $minusOne) {
                continue;
            }

            for ($i = 1; $i < $doublings; $i++) {
                $x = bcmod(bcmul($x, $x), $modulus);

                if ($x === $minusOne) {
                    continue 2;
                }
            }

            return false;
        }

        return true;
    }
}
