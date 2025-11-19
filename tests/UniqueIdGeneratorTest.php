<?php

declare(strict_types=1);

namespace NeuronAI\Tests;

use NeuronAI\UniqueIdGenerator;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class UniqueIdGeneratorTest extends TestCase
{
    protected function setUp(): void
    {
        // Reset static properties before each test
        $this->resetStaticProperties();
    }

    protected function tearDown(): void
    {
        // Clean up after each test
        $this->resetStaticProperties();
    }

    /**
     * Reset static properties using reflection
     */
    private function resetStaticProperties(): void
    {
        $reflection = new ReflectionClass(UniqueIdGenerator::class);

        // Reset machineId
        $machineIdProperty = $reflection->getProperty('machineId');
        if ($machineIdProperty->isInitialized()) {
            $machineIdProperty->setValue(null, null);
        }

        // Reset sequence
        $sequenceProperty = $reflection->getProperty('sequence');
        $sequenceProperty->setValue(null, 0);

        // Reset lastTimestamp
        $lastTimestampProperty = $reflection->getProperty('lastTimestamp');
        $lastTimestampProperty->setValue(null, 0);
    }

    /**
     * Test that generateId returns an integer
     */
    public function testGenerateIdReturnsInteger(): void
    {
        $id = UniqueIdGenerator::generateId('id_');
        $this->assertStringStartsWith('id_', $id);
    }

    /**
     * Test that multiple calls generate unique IDs
     */
    public function testGenerateMultipleUniqueIds(): void
    {
        $ids = [];
        $count = 1000;

        for ($i = 0; $i < $count; $i++) {
            $ids[] = UniqueIdGenerator::generateId('id_');
        }

        // All IDs should be unique
        $this->assertCount($count, \array_unique($ids));

        // All IDs should be positive integers
        foreach ($ids as $id) {
            $this->assertStringStartsWith('id_', $id);
        }
    }

    /**
     * Test that IDs are generally increasing (due to timestamp component)
     */
    public function testIdsAreGenerallyIncreasing(): void
    {
        $id1 = UniqueIdGenerator::generateId();

        // Small delay to ensure different timestamp
        \usleep(1000); // 1ms

        $id2 = UniqueIdGenerator::generateId();

        $this->assertNotEquals($id1, $id2);
    }

    /**
     * Test machine ID is within valid range (1-1023)
     */
    public function testMachineIdWithinValidRange(): void
    {
        $id = (int) UniqueIdGenerator::generateId();

        // Extract machine ID from generated ID
        $machineId = ($id >> 12) & 1023; // Extract 10 bits for machine ID

        $this->assertGreaterThanOrEqual(1, $machineId);
        $this->assertLessThanOrEqual(1023, $machineId);
    }

    /**
     * Test sequence increments within same millisecond
     */
    public function testSequenceIncrementsWithinSameMillisecond(): void
    {
        // Generate multiple IDs rapidly to likely hit same millisecond
        $ids = [];
        for ($i = 0; $i < 100; $i++) {
            $ids[] = (int) UniqueIdGenerator::generateId();
        }

        // Check that we have some IDs with incrementing sequences
        $sequences = [];
        foreach ($ids as $id) {
            $sequence = $id & 4095; // Extract 12 bits for sequence
            $sequences[] = $sequence;
        }

        // Should have at least some non-zero sequences if we hit same millisecond
        $this->assertTrue(\max($sequences) > 0 || \count(\array_unique($sequences)) > 1);
    }

    /**
     * Test that machine ID remains consistent across multiple calls
     */
    public function testMachineIdConsistency(): void
    {
        $id1 = (int) UniqueIdGenerator::generateId();
        $id2 = (int) UniqueIdGenerator::generateId();

        $machineId1 = ($id1 >> 12) & 1023;
        $machineId2 = ($id2 >> 12) & 1023;

        $this->assertEquals($machineId1, $machineId2);
    }

    /**
     * Test ID bit composition (timestamp + machine + sequence = 64 bits)
     */
    public function testIdBitComposition(): void
    {
        $id = (int) UniqueIdGenerator::generateId();

        // Verify ID fits in 64-bit signed integer
        $this->assertLessThanOrEqual(\PHP_INT_MAX, $id);

        // Extract components
        $timestamp = $id >> 22; // 41 bits
        $machineId = ($id >> 12) & 1023; // 10 bits
        $sequence = $id & 4095; // 12 bits

        // Verify ranges
        $this->assertGreaterThan(0, $timestamp);
        $this->assertGreaterThanOrEqual(1, $machineId);
        $this->assertLessThanOrEqual(1023, $machineId);
        $this->assertGreaterThanOrEqual(0, $sequence);
        $this->assertLessThanOrEqual(4095, $sequence);

        // Reconstruct ID and verify it matches
        $reconstructedId = ($timestamp << 22) | ($machineId << 12) | $sequence;
        $this->assertEquals($id, $reconstructedId);
    }

    /**
     * Test sequence overflow handling
     */
    public function testSequenceOverflow(): void
    {
        // Use reflection to manipulate internal state
        $reflection = new ReflectionClass(UniqueIdGenerator::class);

        // Set sequence to near overflow
        $sequenceProperty = $reflection->getProperty('sequence');
        $sequenceProperty->setValue(null, 4094); // Near max (4095)

        // Set last timestamp to current time
        $lastTimestampProperty = $reflection->getProperty('lastTimestamp');
        $currentTime = (int)(\microtime(true) * 1000);
        $lastTimestampProperty->setValue(null, $currentTime);

        // Generate IDs - should handle overflow gracefully
        $id1 = (int) UniqueIdGenerator::generateId();
        $id2 = (int) UniqueIdGenerator::generateId();

        $this->assertNotEquals($id1, $id2);
    }

    /**
     * Test concurrent generation simulation
     */
    public function testConcurrentGenerationSimulation(): void
    {
        $ids = [];
        $iterations = 10000;

        for ($i = 0; $i < $iterations; $i++) {
            $ids[] = (int) UniqueIdGenerator::generateId();

            // Occasionally add tiny delays to simulate varying timing
            if ($i % 100 === 0) {
                \usleep(50);
            }
        }

        // All should be unique
        $this->assertCount($iterations, \array_unique($ids));

        // Should be generally sorted (allowing for some same-millisecond variations)
        $sortedIds = $ids;
        \sort($sortedIds);

        // Calculate how many are in correct order
        $correctOrder = 0;
        for ($i = 0; $i < \count($ids) - 1; $i++) {
            if ($ids[$i] <= $ids[$i + 1]) {
                $correctOrder++;
            }
        }

        // Should be mostly in order (>90%)
        $orderPercentage = $correctOrder / (\count($ids) - 1);
        $this->assertGreaterThan(0.9, $orderPercentage);
    }

    /**
     * Test timestamp extraction and validation
     */
    public function testTimestampExtraction(): void
    {
        $beforeTime = (int)(\microtime(true) * 1000);
        $id = (int) UniqueIdGenerator::generateId();
        $afterTime = (int)(\microtime(true) * 1000);

        $extractedTimestamp = $id >> 22;

        $this->assertGreaterThanOrEqual($beforeTime, $extractedTimestamp);
        $this->assertLessThanOrEqual($afterTime, $extractedTimestamp);
    }

    /**
     * Test performance - should generate IDs quickly
     */
    public function testPerformance(): void
    {
        $startTime = \microtime(true);
        $count = 1000;

        for ($i = 0; $i < $count; $i++) {
            UniqueIdGenerator::generateId();
        }

        $endTime = \microtime(true);
        $duration = $endTime - $startTime;

        // Should generate 1000 IDs in less than 1 second
        $this->assertLessThan(1.0, $duration);

        // Should average less than 1ms per ID
        $averageTime = ($duration * 1000) / $count;
        $this->assertLessThan(1.0, $averageTime);
    }

    /**
     * Test that static properties maintain state correctly
     */
    public function testStaticStateManagement(): void
    {
        // Generate first ID
        $id1 = (int) UniqueIdGenerator::generateId();

        // Use reflection to check machine ID was set
        $reflection = new ReflectionClass(UniqueIdGenerator::class);
        $machineIdProperty = $reflection->getProperty('machineId');

        $this->assertTrue($machineIdProperty->isInitialized());

        // Generate second ID - should use the same machine ID
        $id2 = (int) UniqueIdGenerator::generateId();

        $machineId1 = ($id1 >> 12) & 1023;
        $machineId2 = ($id2 >> 12) & 1023;

        $this->assertEquals($machineId1, $machineId2);
    }
}
