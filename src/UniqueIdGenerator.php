<?php

namespace NeuronAI;

/**
 * Timestamp (41 bits) + Machine ID (10 bits) + Sequence (12 bits) = 64 bits PHP integer limit
 */
class UniqueIdGenerator {
    private static $machineId;
    private static $sequence = 0;
    private static $lastTimestamp = 0;

    public static function generateId() {
        // Initialize machine ID once (you can set this based on server/process)
        if (self::$machineId === null) {
            self::$machineId = mt_rand(1, 1023); // 10 bits
        }

        $timestamp = self::getCurrentTimestamp();

        // If same millisecond, increment sequence
        if ($timestamp === self::$lastTimestamp) {
            self::$sequence = (self::$sequence + 1) & 4095; // 12 bits max

            // If sequence overflow, wait for next millisecond
            if (self::$sequence === 0) {
                $timestamp = self::waitForNextTimestamp(self::$lastTimestamp);
            }
        } else {
            self::$sequence = 0;
        }

        self::$lastTimestamp = $timestamp;

        // Combine: timestamp (41 bits) + machine ID (10 bits) + sequence (12 bits)
        $id = ($timestamp << 22) | (self::$machineId << 12) | self::$sequence;

        return $id;
    }

    private static function getCurrentTimestamp() {
        return (int)(microtime(true) * 1000);
    }

    private static function waitForNextTimestamp($lastTimestamp) {
        $timestamp = self::getCurrentTimestamp();
        while ($timestamp <= $lastTimestamp) {
            usleep(100); // Wait 0.1ms
            $timestamp = self::getCurrentTimestamp();
        }
        return $timestamp;
    }
}
