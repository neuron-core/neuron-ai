<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools\Toolkits\Calendar;

use NeuronAI\Tools\Toolkits\Calendar\ConvertTimezoneTool;
use NeuronAI\Tools\ToolPropertyInterface;
use PHPUnit\Framework\TestCase;

use function array_map;

class ConvertTimezoneToolTest extends TestCase
{
    private ConvertTimezoneTool $tool;

    protected function setUp(): void
    {
        $this->tool = new ConvertTimezoneTool();
    }

    public function test_convert_utc_to_new_york(): void
    {
        $result = ($this->tool)('2023-06-15 12:00:00', 'UTC', 'America/New_York');

        $this->assertEquals('2023-06-15 08:00:00 EDT', $result);
    }

    public function test_convert_utc_to_london(): void
    {
        $result = ($this->tool)('2023-06-15 12:00:00', 'UTC', 'Europe/London');

        $this->assertEquals('2023-06-15 13:00:00 BST', $result);
    }

    public function test_convert_new_york_to_utc(): void
    {
        $result = ($this->tool)('2023-06-15 08:00:00', 'America/New_York', 'UTC');

        $this->assertEquals('2023-06-15 12:00:00 UTC', $result);
    }

    public function test_convert_across_multiple_timezones(): void
    {
        $result = ($this->tool)('2023-06-15 14:30:00', 'Europe/London', 'Asia/Tokyo');

        $this->assertEquals('2023-06-15 22:30:00 JST', $result);
    }

    public function test_convert_with_timestamp(): void
    {
        $timestamp = '1686834000'; // 2023-06-15 14:00:00 UTC
        $result = ($this->tool)($timestamp, 'UTC', 'America/Los_Angeles');

        $this->assertEquals('2023-06-15 06:00:00 PDT', $result);
    }

    public function test_convert_with_custom_format(): void
    {
        $result = ($this->tool)('2023-06-15 12:00:00', 'UTC', 'America/New_York', 'Y-m-d H:i');

        $this->assertEquals('2023-06-15 08:00', $result);
    }

    public function test_convert_with_iso_format(): void
    {
        $result = ($this->tool)('2023-06-15 12:00:00', 'UTC', 'Europe/Berlin', 'c');

        $this->assertEquals('2023-06-15T14:00:00+02:00', $result);
    }

    public function test_convert_during_dst_transition(): void
    {
        // Test during EST (winter time)
        $winterResult = ($this->tool)('2023-01-15 12:00:00', 'UTC', 'America/New_York');
        $this->assertEquals('2023-01-15 07:00:00 EST', $winterResult);

        // Test during EDT (summer time)
        $summerResult = ($this->tool)('2023-07-15 12:00:00', 'UTC', 'America/New_York');
        $this->assertEquals('2023-07-15 08:00:00 EDT', $summerResult);
    }

    public function test_convert_same_timezone(): void
    {
        $result = ($this->tool)('2023-06-15 12:00:00', 'UTC', 'UTC');

        $this->assertEquals('2023-06-15 12:00:00 UTC', $result);
    }

    public function test_convert_with_seconds_and_microseconds(): void
    {
        $result = ($this->tool)('2023-06-15 12:30:45', 'UTC', 'America/Chicago', 'Y-m-d H:i:s T');

        $this->assertEquals('2023-06-15 07:30:45 CDT', $result);
    }

    public function test_convert_negative_timezone_offset(): void
    {
        $result = ($this->tool)('2023-06-15 12:00:00', 'UTC', 'Pacific/Honolulu');

        $this->assertEquals('2023-06-15 02:00:00 HST', $result);
    }

    public function test_convert_positive_timezone_offset(): void
    {
        $result = ($this->tool)('2023-06-15 12:00:00', 'UTC', 'Asia/Dubai');

        $this->assertEquals('2023-06-15 16:00:00 +04', $result);
    }

    public function test_convert_across_dst_boundary(): void
    {
        // Convert from a timezone without DST to one with DST
        $result = ($this->tool)('2023-06-15 12:00:00', 'Asia/Dubai', 'Europe/Paris');

        $this->assertEquals('2023-06-15 10:00:00 CEST', $result);
    }

    public function test_invalid_date(): void
    {
        $result = ($this->tool)('invalid-date', 'UTC', 'America/New_York');

        $this->assertStringStartsWith('Error:', $result);
    }

    public function test_invalid_from_timezone(): void
    {
        $result = ($this->tool)('2023-06-15 12:00:00', 'Invalid/Timezone', 'America/New_York');

        $this->assertStringStartsWith('Error:', $result);
    }

    public function test_invalid_to_timezone(): void
    {
        $result = ($this->tool)('2023-06-15 12:00:00', 'UTC', 'Invalid/Timezone');

        $this->assertStringStartsWith('Error:', $result);
    }

    public function test_tool_properties(): void
    {
        $this->assertEquals('convert_timezone', $this->tool->getName());
        $this->assertEquals('Convert a date/time from one timezone to another', $this->tool->getDescription());

        $properties = $this->tool->getProperties();
        $this->assertCount(4, $properties);

        $propertyNames = array_map(fn (ToolPropertyInterface $prop): string => $prop->getName(), $properties);
        $this->assertContains('date', $propertyNames);
        $this->assertContains('from_timezone', $propertyNames);
        $this->assertContains('to_timezone', $propertyNames);
        $this->assertContains('format', $propertyNames);
    }
}
