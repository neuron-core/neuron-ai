<?php

declare(strict_types=1);

namespace NeuronAI;

/**
 * Timestamp (41 bits) + Machine ID (10 bits) + Sequence (12 bits) = 64 bits PHP integer limit
 */
class UniqueIdGenerator
{
    protected static ?int $machineId = null;
    protected static int $sequence = 0;
    protected static int $lastTimestamp = 0;

    public static function generateId(): int
    {
        // Initialize machine ID once (you can set this based on server/process)
        if (self::$machineId === null) {
            self::$machineId = \mt_rand(1, 1023); // 10 bits
        }

        $timestamp = self::getCurrentTimestamp();

        // If same millisecond, increment sequence
        if ($timestamp === self::$lastTimestamp) {
            self::$sequence = (self::$sequence + 1) & 4095; // 12 bits max

            // If the sequence overflows, wait for the next millisecond
            if (self::$sequence === 0) {
                $timestamp = self::waitForNextTimestamp(self::$lastTimestamp);
            }
        } else {
            self::$sequence = 0;
        }

        self::$lastTimestamp = $timestamp;

        // Combine: timestamp (41 bits) + machine ID (10 bits) + sequence (12 bits)
        return ($timestamp << 22) | (self::$machineId << 12) | self::$sequence;
    }

    protected static function getCurrentTimestamp(): int
    {
        return (int)(\microtime(true) * 1000);
    }

    protected static function waitForNextTimestamp(int $lastTimestamp): int
    {
        $timestamp = self::getCurrentTimestamp();
        while ($timestamp <= $lastTimestamp) {
            \usleep(100); // Wait 0.1ms
            $timestamp = self::getCurrentTimestamp();
        }
        return $timestamp;
    }
}
